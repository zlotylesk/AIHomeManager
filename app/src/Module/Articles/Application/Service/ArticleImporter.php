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
    public function __construct(
        private ArticleRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {
    }

    public function import(string $filePath): ImportResult
    {
        $result = new ImportResult();

        $handle = fopen($filePath, 'r');
        if (false === $handle) {
            throw new RuntimeException(sprintf('Cannot open file: %s', $filePath));
        }

        try {
            $sample = fread($handle, 8192);
            rewind($handle);

            $encoding = mb_detect_encoding($sample, ['UTF-8', 'ISO-8859-2'], true) ?: 'UTF-8';
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
