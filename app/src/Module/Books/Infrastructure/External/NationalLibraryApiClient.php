<?php

declare(strict_types=1);

namespace App\Module\Books\Infrastructure\External;

use App\Module\Books\Application\DTO\BookMetadataDTO;
use App\Module\Books\Application\Exception\BookMetadataNotFoundException;
use App\Module\Books\Application\Exception\BookMetadataUnavailableException;
use App\Module\Books\Domain\Port\BookMetadataProviderInterface;
use JsonException;
use Redis;
use RuntimeException;
use SimpleXMLElement;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class NationalLibraryApiClient implements BookMetadataProviderInterface
{
    private const string API_URL = 'https://api.bn.org.pl/api/bibs';
    private const int CACHE_TTL = 86400;
    private const string CACHE_PREFIX = 'book:metadata:';

    public function __construct(
        private HttpClientInterface $httpClient,
        private Redis $redis,
    ) {
    }

    public function getByIsbn(string $isbn): BookMetadataDTO
    {
        $cacheKey = self::CACHE_PREFIX.$isbn;

        $cached = $this->redis->get($cacheKey);
        if (false !== $cached) {
            try {
                return $this->decodeMetadataFromCache($cached);
            } catch (JsonException) {
                // Stale or corrupted cache entry — fall through to refetch.
            }
        }

        try {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => ['isbnissn' => $isbn, 'kind' => 'book', 'limit' => 1],
                'timeout' => 5,
            ]);

            $content = $response->getContent();
        } catch (TransportExceptionInterface $e) {
            throw new BookMetadataUnavailableException('National Library API is unavailable.', 0, $e);
        }

        // Use simplexml_load_string + libxml internal errors instead of `new SimpleXMLElement`
        // so a malformed payload returns false (handled below) instead of throwing a base
        // \Exception that a broad catch would also swallow programmer errors with.
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        if (false === $xml) {
            throw new RuntimeException('Failed to parse National Library API response.');
        }

        $bibs = $xml->bibs ?? $xml;
        $bibNodes = $bibs->bib ?? [];

        if (0 === count($bibNodes)) {
            throw new BookMetadataNotFoundException('Book not found in National Library.');
        }

        $dto = $this->parseBib($bibNodes[0]);

        $this->redis->setex($cacheKey, self::CACHE_TTL, $this->encodeMetadataForCache($dto));

        return $dto;
    }

    private function encodeMetadataForCache(BookMetadataDTO $dto): string
    {
        return json_encode([
            'title' => $dto->title,
            'author' => $dto->author,
            'publisher' => $dto->publisher,
            'year' => $dto->year,
            'totalPages' => $dto->totalPages,
            'coverUrl' => $dto->coverUrl,
        ], JSON_THROW_ON_ERROR);
    }

    private function decodeMetadataFromCache(string $json): BookMetadataDTO
    {
        $row = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new BookMetadataDTO(
            title: (string) ($row['title'] ?? ''),
            author: isset($row['author']) ? (string) $row['author'] : null,
            publisher: isset($row['publisher']) ? (string) $row['publisher'] : null,
            year: isset($row['year']) ? (int) $row['year'] : null,
            totalPages: isset($row['totalPages']) ? (int) $row['totalPages'] : null,
            coverUrl: isset($row['coverUrl']) ? (string) $row['coverUrl'] : null,
        );
    }

    private function parseBib(SimpleXMLElement $bib): BookMetadataDTO
    {
        $dc = $bib->children('http://purl.org/dc/elements/1.1/');

        $title = trim((string) ($dc->title ?? '')) ?: null;

        if (null === $title) {
            throw new BookMetadataNotFoundException('Book not found in National Library.');
        }

        $author = trim((string) ($dc->creator ?? '')) ?: null;
        $publisher = trim((string) ($dc->publisher ?? '')) ?: null;

        $yearStr = trim((string) ($dc->date ?? ''));
        $year = '' !== $yearStr ? (int) $yearStr : null;

        $formatStr = trim((string) ($dc->format ?? ''));
        $totalPages = null;
        if ('' !== $formatStr && preg_match('/\d+/', $formatStr, $matches)) {
            $totalPages = (int) $matches[0];
        }

        return new BookMetadataDTO(
            title: $title,
            author: $author,
            publisher: $publisher,
            year: $year,
            totalPages: $totalPages,
            coverUrl: null,
        );
    }
}
