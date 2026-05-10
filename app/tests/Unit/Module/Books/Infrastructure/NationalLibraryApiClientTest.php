<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Books\Infrastructure;

use App\Module\Books\Application\Exception\BookMetadataNotFoundException;
use App\Module\Books\Application\Exception\BookMetadataUnavailableException;
use App\Module\Books\Infrastructure\External\NationalLibraryApiClient;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NationalLibraryApiClientTest extends TestCase
{
    private Redis $redis;

    protected function setUp(): void
    {
        $this->redis = $this->createStub(Redis::class);
        $this->redis->method('get')->willReturn(false);
        $this->redis->method('setex')->willReturn(true);
    }

    private function makeXml(array $fields = []): string
    {
        $dc = '';
        foreach ($fields as $key => $value) {
            $dc .= sprintf('<dc:%s xmlns:dc="http://purl.org/dc/elements/1.1/">%s</dc:%s>', $key, htmlspecialchars((string) $value), $key);
        }

        return sprintf('<?xml version="1.0" encoding="UTF-8"?><bibs><bib>%s</bib></bibs>', $dc);
    }

    public function testReturnsBookMetadataFromValidXmlResponse(): void
    {
        $xml = $this->makeXml([
            'title' => 'Clean Code',
            'creator' => 'Robert C. Martin',
            'publisher' => 'Prentice Hall',
            'date' => '2008',
            'format' => '320 s.',
        ]);

        $httpClient = new MockHttpClient(new MockResponse($xml));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $dto = $client->getByIsbn('9780306406157');

        self::assertSame('Clean Code', $dto->title);
        self::assertSame('Robert C. Martin', $dto->author);
        self::assertSame('Prentice Hall', $dto->publisher);
        self::assertSame(2008, $dto->year);
        self::assertSame(320, $dto->totalPages);
    }

    public function testParsesTotalPagesFromFormatString(): void
    {
        $xml = $this->makeXml(['title' => 'Book', 'format' => '256 stron']);
        $httpClient = new MockHttpClient(new MockResponse($xml));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $dto = $client->getByIsbn('9780306406157');

        self::assertSame(256, $dto->totalPages);
    }

    public function testHandlesPartialMetadataWithoutTotalPages(): void
    {
        $xml = $this->makeXml(['title' => 'Partial Book']);
        $httpClient = new MockHttpClient(new MockResponse($xml));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $dto = $client->getByIsbn('9780306406157');

        self::assertSame('Partial Book', $dto->title);
        self::assertNull($dto->author);
        self::assertNull($dto->totalPages);
    }

    public function testThrowsNotFoundWhenNoBibReturned(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><bibs></bibs>';
        $httpClient = new MockHttpClient(new MockResponse($xml));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $this->expectException(BookMetadataNotFoundException::class);

        $client->getByIsbn('0000000001');
    }

    public function testThrowsRuntimeExceptionOnMalformedXmlResponse(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('<<not-valid-xml'));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse National Library API response.');

        $client->getByIsbn('9780306406157');
    }

    public function testThrowsUnavailableOnTransportException(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection timeout']));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $this->expectException(BookMetadataUnavailableException::class);

        $client->getByIsbn('9780306406157');
    }

    public function testReturnsCachedResultWithoutHttpCall(): void
    {
        $cachePayload = json_encode([
            'title' => 'Cached Book',
            'author' => 'Author',
            'publisher' => 'Publisher',
            'year' => 2020,
            'totalPages' => 100,
            'coverUrl' => null,
        ], JSON_THROW_ON_ERROR);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn($cachePayload);
        $redis->expects(self::never())->method('setex');

        $httpClient = new MockHttpClient();
        $client = new NationalLibraryApiClient($httpClient, $redis);

        $result = $client->getByIsbn('9780306406157');

        self::assertSame('Cached Book', $result->title);
        self::assertSame('Author', $result->author);
        self::assertSame('Publisher', $result->publisher);
        self::assertSame(2020, $result->year);
        self::assertSame(100, $result->totalPages);
        self::assertNull($result->coverUrl);
    }

    public function testCorruptedCacheFallsBackToApiFetch(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn('not-valid-json');
        $redis->expects(self::once())->method('setex')->willReturn(true);

        $xml = $this->makeXml(['title' => 'Refetched Book']);
        $httpClient = new MockHttpClient(new MockResponse($xml));
        $client = new NationalLibraryApiClient($httpClient, $redis);

        $result = $client->getByIsbn('9780306406157');

        self::assertSame('Refetched Book', $result->title);
    }
}
