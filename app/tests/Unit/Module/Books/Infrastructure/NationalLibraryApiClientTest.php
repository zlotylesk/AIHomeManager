<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Books\Infrastructure;

use App\Module\Books\Application\Exception\BookMetadataNotFoundException;
use App\Module\Books\Application\Exception\BookMetadataUnavailableException;
use App\Module\Books\Infrastructure\External\NationalLibraryApiClient;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @param array<string, string|int> $fields
     */
    private function makeXml(array $fields = [], ?string $marcPages = null): string
    {
        $body = '';
        foreach ($fields as $key => $value) {
            $body .= sprintf('<%s>%s</%s>', $key, htmlspecialchars((string) $value), $key);
        }

        if (null !== $marcPages) {
            $body .= sprintf(
                '<marc xmlns="http://www.loc.gov/MARC21/slim">'
                .'<datafield tag="300"><subfield code="a">%s</subfield></datafield>'
                .'</marc>',
                htmlspecialchars($marcPages),
            );
        }

        return sprintf('<?xml version="1.0" encoding="UTF-8"?><resp><bibs><bib>%s</bib></bibs></resp>', $body);
    }

    public function testReturnsBookMetadataFromValidXmlResponse(): void
    {
        $xml = $this->makeXml(
            [
                'title' => 'Clean Code',
                'author' => 'Robert C. Martin',
                'publisher' => 'Prentice Hall',
                'publicationYear' => '2008',
            ],
            '320 s. ;',
        );

        $httpClient = new MockHttpClient(new MockResponse($xml));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $dto = $client->getByIsbn('9780306406157');

        self::assertSame('Clean Code', $dto->title);
        self::assertSame('Robert C. Martin', $dto->author);
        self::assertSame('Prentice Hall', $dto->publisher);
        self::assertSame(2008, $dto->year);
        self::assertSame(320, $dto->totalPages);
    }

    /**
     * @return array<string, array{0: string, 1: ?int}>
     */
    public static function marcPagesProvider(): array
    {
        return [
            'plain "320 s." form' => ['320 s.', 320],
            'trailing semicolon' => ['320 s. ;', 320],
            'bracketed appendix pages' => ['200, [4] s.', 200],
            'Polish "stron" word' => ['150 stron', 150],
            'high page count' => ['1024 s.', 1024],
            'non-paginated media (CD-ROM)' => ['1 dysk optyczny (CD-ROM)', null],
            'empty subfield' => ['', null],
        ];
    }

    #[DataProvider('marcPagesProvider')]
    public function testExtractsTotalPagesFromMarcDatafield300(string $marcAValue, ?int $expected): void
    {
        $xml = $this->makeXml(['title' => 'Sample'], $marcAValue);
        $httpClient = new MockHttpClient(new MockResponse($xml));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $dto = $client->getByIsbn('9780306406157');

        self::assertSame($expected, $dto->totalPages);
    }

    public function testHandlesPartialMetadataWithoutMarcBlock(): void
    {
        // Without a <marc> child, totalPages defaults to null and the handler
        // surfaces the "fill it in manually" message to the user — the API
        // call itself still succeeds.
        $xml = $this->makeXml(['title' => 'Partial Book']);
        $httpClient = new MockHttpClient(new MockResponse($xml));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $dto = $client->getByIsbn('9780306406157');

        self::assertSame('Partial Book', $dto->title);
        self::assertNull($dto->author);
        self::assertNull($dto->publisher);
        self::assertNull($dto->year);
        self::assertNull($dto->totalPages);
    }

    public function testThrowsNotFoundWhenNoBibReturned(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><resp><bibs></bibs></resp>';
        $httpClient = new MockHttpClient(new MockResponse($xml));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $this->expectException(BookMetadataNotFoundException::class);

        $client->getByIsbn('0000000001');
    }

    public function testThrowsNotFoundWhenBibHasNoTitle(): void
    {
        // BN occasionally returns a <bib> with author/publisher but no <title>
        // (placeholder / in-progress catalogue entry). Without a title the DTO
        // is useless for our purposes — treat it the same as "not found".
        $xml = $this->makeXml(['author' => 'Anonymous']);
        $httpClient = new MockHttpClient(new MockResponse($xml));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $this->expectException(BookMetadataNotFoundException::class);

        $client->getByIsbn('9780306406157');
    }

    public function testThrowsRuntimeExceptionOnMalformedXmlResponse(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('<<not-valid-xml'));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse National Library API response.');

        $client->getByIsbn('9780306406157');
    }

    public function testRejectsXxePayloadWithDoctype(): void
    {
        // Regression for HMAI-96: a classic XXE attempt — external SYSTEM entity
        // pointing at /etc/passwd. Even if BN.org were compromised or MitM'd,
        // the DOCTYPE pre-check must reject the response outright, so the
        // attacker's entity never reaches libxml.
        $xxe = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
            <resp><bibs><bib><title>&xxe;</title></bib></bibs></resp>
            XML;

        $httpClient = new MockHttpClient(new MockResponse($xxe));
        $client = new NationalLibraryApiClient($httpClient, $this->redis);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse National Library API response.');

        $client->getByIsbn('9780306406157');
    }

    public function testRejectsDoctypeRegardlessOfCase(): void
    {
        // Belt-and-suspenders: stripos() ensures lowercase "<!doctype" and
        // whitespace-padded variants are caught — a naive substr() match could
        // be bypassed by such trivial obfuscation.
        $payload = '<?xml version="1.0"?><!doctype resp><resp><bibs><bib><title>Innocent</title></bib></bibs></resp>';
        $httpClient = new MockHttpClient(new MockResponse($payload));
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
