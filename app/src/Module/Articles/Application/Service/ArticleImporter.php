<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Service;

use App\Module\Articles\Application\DTO\ImportResult;
use App\Module\Articles\Domain\Entity\Article;
use App\Module\Articles\Domain\Repository\ArticleRepositoryInterface;
use App\Module\Articles\Domain\ValueObject\ArticleUrl;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final readonly class ArticleImporter
{
    /**
     * Encodings accepted for the explicit `$encoding` override. iconv decodes
     * all of these reliably during conversion to UTF-8.
     *
     * Pocket exports from Polish users have historically come in three flavors:
     * - UTF-8 (default for new exports)
     * - ISO-8859-2 (older Linux/Mac exports)
     * - Windows-1250 (common when the source file was edited on Polish Windows)
     *
     * Windows-1252 is added defensively for accidental WE-locale exports — many
     * codepoints overlap with ISO-8859-1 but iconv-via-UTF-8 still recovers ASCII.
     */
    private const array SUPPORTED_ENCODINGS = ['UTF-8', 'ISO-8859-2', 'Windows-1250', 'Windows-1252'];

    /**
     * Encodings used for auto-detection. NOTE: mbstring does NOT support
     * Windows-1250 (`mb_list_encodings()` lists only -1251/-1252/-1254), so
     * Polish-Windows exports cannot be auto-detected and must be passed via
     * the explicit `$encoding` parameter — this is intentional per HMAI-81.
     */
    private const array DETECTABLE_ENCODINGS = ['UTF-8', 'ISO-8859-2', 'Windows-1252'];

    public function __construct(
        private ArticleRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string      $filePath path to the CSV file to import
     * @param string|null $encoding when non-null, skips auto-detection and decodes
     *                              the whole file as this encoding. Must be one of
     *                              {@see self::SUPPORTED_ENCODINGS}. Use this when
     *                              you know the source (e.g. a Polish Pocket
     *                              export saved as Windows-1250) — auto-detect on
     *                              an 8 KB sample can misidentify short files.
     */
    public function import(string $filePath, ?string $encoding = null): ImportResult
    {
        if (null !== $encoding && !in_array($encoding, self::SUPPORTED_ENCODINGS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported encoding "%s". Supported: %s', $encoding, implode(', ', self::SUPPORTED_ENCODINGS)));
        }

        $result = new ImportResult();

        $handle = fopen($filePath, 'r');
        if (false === $handle) {
            throw new RuntimeException(sprintf('Cannot open file: %s', $filePath));
        }

        try {
            $sample = fread($handle, 8192) ?: '';
            rewind($handle);

            $encoding ??= $this->detectEncoding($sample, $filePath);
            $needsConversion = 'UTF-8' !== $encoding;

            $header = fgetcsv($handle, 0, ',', '"', '');
            if (false === $header) {
                return $result;
            }

            if ($needsConversion) {
                $header = array_map(fn ($v) => iconv($encoding, 'UTF-8//TRANSLIT', $v ?? ''), $header);
            }
            $columns = array_flip(array_map(trim(...), $header));

            while (($fields = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($needsConversion) {
                    $fields = array_map(fn ($v) => iconv($encoding, 'UTF-8//TRANSLIT', $v ?? ''), $fields);
                }

                $row = [];
                foreach ($columns as $col => $idx) {
                    $row[$col] = $fields[$idx] ?? null;
                }

                $this->processRow($row, $result);
            }
        } finally {
            fclose($handle);
        }

        return $result;
    }

    /**
     * Tries the supported encodings in strict mode. Strict means mb_detect_encoding
     * returns false unless the byte sequence is unambiguously valid in one of the
     * candidates — for very short or ASCII-only samples this happily picks UTF-8.
     *
     * The only case we log is when detection returns false: that means the bytes
     * are valid in *none* of the candidates, so we cannot recover safely. Falling
     * back to UTF-8 will mangle non-ASCII bytes; the warning gives ops enough hex
     * context to spot the actual encoding and re-run with $encoding= override.
     */
    private function detectEncoding(string $sample, string $filePath): string
    {
        $detected = mb_detect_encoding($sample, self::DETECTABLE_ENCODINGS, true);
        if (false !== $detected) {
            return $detected;
        }

        $this->logger->warning('ArticleImporter: encoding auto-detect failed, falling back to UTF-8 (data may be mangled)', [
            'file' => $filePath,
            'tried' => self::DETECTABLE_ENCODINGS,
            'bytes_sample' => bin2hex(substr($sample, 0, 64)),
            'hint' => 'pass $encoding explicitly to import() if you know the source encoding (e.g. "Windows-1250")',
        ]);

        return 'UTF-8';
    }

    private function processRow(array $row, ImportResult $result): void
    {
        $title = trim($row['title'] ?? '');
        $rawUrl = trim($row['url'] ?? '');

        if ('' === $title || '' === $rawUrl) {
            $this->logger->warning('Skipping row: missing required title or url', ['row' => $row]);
            ++$result->errors;

            return;
        }

        try {
            $url = new ArticleUrl($rawUrl);
        } catch (InvalidArgumentException $e) {
            $this->logger->warning('Skipping row: invalid URL', ['url' => $rawUrl, 'error' => $e->getMessage()]);
            ++$result->errors;

            return;
        }

        if ($this->repository->existsByUrl($rawUrl)) {
            ++$result->skipped;

            return;
        }

        $isRead = 'archive' === trim($row['status'] ?? '');

        $timeAdded = new DateTimeImmutable();
        if (!empty($row['time_added'])) {
            $parsed = DateTimeImmutable::createFromFormat('U', $row['time_added']);
            if (false !== $parsed) {
                $timeAdded = $parsed;
            }
        }

        $readAt = $isRead ? $timeAdded : null;

        $tags = !empty($row['tags']) ? trim((string) $row['tags']) : null;
        $category = $tags ?? (!empty($row['category']) ? trim((string) $row['category']) : null);

        $estimatedReadTime = isset($row['estimated_read_time']) && '' !== $row['estimated_read_time']
            ? (int) $row['estimated_read_time']
            : null;

        $article = new Article(
            id: Uuid::v4()->toRfc4122(),
            title: $title,
            url: $url,
            category: $category ?: null,
            estimatedReadTime: $estimatedReadTime,
            addedAt: $timeAdded,
            readAt: $readAt,
            isRead: $isRead,
        );

        $this->repository->save($article);
        ++$result->imported;
    }
}
