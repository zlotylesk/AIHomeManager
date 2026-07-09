<?php

declare(strict_types=1);

namespace App\Module\Books\Infrastructure\External;

use App\Module\Books\Application\Exception\BookMetadataNotFoundException;
use App\Module\Books\Application\Exception\BookMetadataUnavailableException;
use App\Module\Books\Domain\Port\BookMetadataProviderInterface;
use App\Module\Books\Domain\ReadModel\BookMetadata;
use JsonException;
use Redis;
use RuntimeException;
use SimpleXMLElement;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class NationalLibraryApiClient implements BookMetadataProviderInterface
{
    private const string API_URL = 'https://data.bn.org.pl/api/bibs.xml';
    private const string MARC_NAMESPACE = 'http://www.loc.gov/MARC21/slim';
    private const int CACHE_TTL = 86400;
    private const string CACHE_PREFIX = 'book:metadata:';

    public function __construct(
        private HttpClientInterface $httpClient,
        private Redis $redis,
    ) {
    }

    public function getByIsbn(string $isbn): BookMetadata
    {
        $cacheKey = self::CACHE_PREFIX.$isbn;

        $cached = $this->redis->get($cacheKey);
        if (false !== $cached) {
            try {
                return $this->decodeMetadataFromCache($cached);
            } catch (JsonException) {
            }
        }

        try {
            $response = $this->httpClient->request('GET', self::API_URL, [
                'query' => ['isbnIssn' => $isbn, 'limit' => 1],
                'timeout' => 5,
            ]);

            $content = $response->getContent();
        } catch (TransportExceptionInterface $e) {
            throw new BookMetadataUnavailableException('National Library API is unavailable.', 0, $e);
        }

        if (false !== stripos($content, '<!DOCTYPE')) {
            throw new RuntimeException('Failed to parse National Library API response.');
        }

        $previousErrors = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
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

        $firstBib = $bibNodes[0];
        if (!$firstBib instanceof SimpleXMLElement) {
            throw new BookMetadataNotFoundException('Book not found in National Library.');
        }

        $dto = $this->parseBib($firstBib);

        $this->redis->setex($cacheKey, self::CACHE_TTL, $this->encodeMetadataForCache($dto));

        return $dto;
    }

    private function encodeMetadataForCache(BookMetadata $dto): string
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

    private function decodeMetadataFromCache(string $json): BookMetadata
    {
        $row = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new BookMetadata(
            title: (string) ($row['title'] ?? ''),
            author: isset($row['author']) ? (string) $row['author'] : null,
            publisher: isset($row['publisher']) ? (string) $row['publisher'] : null,
            year: isset($row['year']) ? (int) $row['year'] : null,
            totalPages: isset($row['totalPages']) ? (int) $row['totalPages'] : null,
            coverUrl: isset($row['coverUrl']) ? (string) $row['coverUrl'] : null,
        );
    }

    private function parseBib(SimpleXMLElement $bib): BookMetadata
    {
        $title = trim((string) ($bib->title ?? '')) ?: null;

        if (null === $title) {
            throw new BookMetadataNotFoundException('Book not found in National Library.');
        }

        $author = trim((string) ($bib->author ?? '')) ?: null;
        $publisher = trim((string) ($bib->publisher ?? '')) ?: null;

        $yearStr = trim((string) ($bib->publicationYear ?? ''));
        $year = '' !== $yearStr ? (int) $yearStr : null;
        if (0 === $year) {
            $year = null;
        }

        return new BookMetadata(
            title: $title,
            author: $author,
            publisher: $publisher,
            year: $year,
            totalPages: $this->extractTotalPagesFromMarc($bib),
            coverUrl: null,
        );
    }

    private function extractTotalPagesFromMarc(SimpleXMLElement $bib): ?int
    {
        $bib->registerXPathNamespace('m', self::MARC_NAMESPACE);
        $subfields = $bib->xpath('m:marc/m:datafield[@tag="300"]/m:subfield[@code="a"]');
        if (!is_array($subfields) || [] === $subfields) {
            return null;
        }

        foreach ($subfields as $subfield) {
            if (1 === preg_match('/(\d+)\s*(?:,\s*\[\d+\])?\s*s(?:tron|\.)?/u', (string) $subfield, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}
