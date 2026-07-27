<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Infrastructure;

use App\Module\Series\Infrastructure\External\TraktApiClient;
use App\Module\Series\Infrastructure\External\TraktTokenProvider;
use App\Module\Series\Infrastructure\Persistence\TraktTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TraktApiClientTest extends TestCase
{
    private const string CLIENT_ID = 'test-trakt-client-id';

    /**
     * Builds a real TraktTokenProvider (the class is final readonly, so it cannot
     * be mocked) over a stub repository. Unless overridden, the token carries a
     * fresh created_at/expires_in pair so getValidAccessToken() returns it as-is
     * without ever touching the network (the refresh path is TraktTokenProviderTest's
     * job, not this client's).
     *
     * @param array<string, mixed>|null $token
     */
    private function tokenProvider(?array $token = ['access_token' => 'access-123']): TraktTokenProvider
    {
        if (null !== $token) {
            $token += [
                'created_at' => time(),
                'expires_in' => 7776000,
            ];
        }

        $repository = $this->createStub(TraktTokenRepositoryInterface::class);
        $repository->method('get')->willReturn($token);

        return new TraktTokenProvider(
            $repository,
            new MockHttpClient([]),
            self::CLIENT_ID,
            'test-trakt-client-secret',
            'https://localhost/auth/trakt/callback',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function watchedShow(): array
    {
        return [
            'show' => [
                'title' => 'Breaking Bad',
                'year' => 2008,
                'ids' => ['trakt' => 1, 'slug' => 'breaking-bad'],
            ],
            'seasons' => [
                [
                    'number' => 1,
                    'episodes' => [
                        ['number' => 1, 'last_watched_at' => '2020-01-01T20:00:00.000Z'],
                        ['number' => 2, 'last_watched_at' => '2020-01-02T20:00:00.000Z'],
                    ],
                ],
            ],
        ];
    }

    public function testParsesWatchedShowsIntoStructuredShape(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([$this->watchedShow()])));
        $client = new TraktApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        $shows = $client->fetchWatchedShows();

        self::assertCount(1, $shows);
        self::assertSame(1, $shows[0]['traktId']);
        self::assertSame('Breaking Bad', $shows[0]['title']);
        self::assertSame(2008, $shows[0]['year']);
        self::assertCount(1, $shows[0]['seasons']);
        self::assertSame(1, $shows[0]['seasons'][0]['number']);
        self::assertCount(2, $shows[0]['seasons'][0]['episodes']);
        self::assertSame(2, $shows[0]['seasons'][0]['episodes'][1]['number']);
        self::assertSame('2020-01-02T20:00:00.000Z', $shows[0]['seasons'][0]['episodes'][1]['lastWatchedAt']);
    }

    public function testReturnsEmptyArrayWhenNoShows(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('[]'));
        $client = new TraktApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        self::assertSame([], $client->fetchWatchedShows());
    }

    public function testSkipsShowsWithoutTraktId(): void
    {
        $noId = $this->watchedShow();
        unset($noId['show']['ids']['trakt']);

        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([$noId, $this->watchedShow()])));
        $client = new TraktApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        $shows = $client->fetchWatchedShows();

        self::assertCount(1, $shows);
        self::assertSame(1, $shows[0]['traktId']);
    }

    public function testSendsTraktAuthHeadersAndExtendedQuery(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured['method'] = $method;
            $captured['url'] = $url;
            $captured['headers'] = implode("\n", $options['headers'] ?? []);

            return new MockResponse('[]');
        });
        $client = new TraktApiClient($httpClient, $this->tokenProvider(['access_token' => 'access-123']), self::CLIENT_ID);

        $client->fetchWatchedShows();

        self::assertSame('GET', $captured['method']);
        self::assertStringContainsString('api.trakt.tv/sync/watched/shows', $captured['url']);
        self::assertStringContainsString('extended=full', $captured['url']);
        self::assertStringContainsString('trakt-api-version: 2', $captured['headers']);
        self::assertStringContainsString('trakt-api-key: '.self::CLIENT_ID, $captured['headers']);
        self::assertStringContainsString('Authorization: Bearer access-123', $captured['headers']);
    }

    public function testThrowsWhenNoTokenStored(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('[]'));
        $client = new TraktApiClient($httpClient, $this->tokenProvider(null), self::CLIENT_ID);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trakt account not connected.');

        $client->fetchWatchedShows();
    }

    public function testThrowsWhenClientIdIsBlank(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('[]'));
        $client = new TraktApiClient($httpClient, $this->tokenProvider(), '   ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trakt client ID not configured.');

        $client->fetchWatchedShows();
    }

    public function testThrowsRuntimeExceptionOnTransportError(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        $client = new TraktApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trakt API unavailable.');

        $client->fetchWatchedShows();
    }

    public function testThrowsRuntimeExceptionOnHttpClientErrorResponse(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"error":"invalid_token"}', ['http_code' => 401]));
        $client = new TraktApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trakt API error (HTTP 401).');

        $client->fetchWatchedShows();
    }

    public function testHttpClientErrorMessageDoesNotLeakTheRequestUrl(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"error":"invalid_token"}', ['http_code' => 401]));
        $client = new TraktApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        try {
            $client->fetchWatchedShows();
            self::fail('Expected a RuntimeException to be thrown.');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('api.trakt.tv', $e->getMessage());
            self::assertStringNotContainsString('/sync/watched/shows', $e->getMessage());
        }
    }

    public function testThrowsRuntimeExceptionOnHttpServerErrorResponse(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['http_code' => 500]));
        $client = new TraktApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trakt API error (HTTP 500).');

        $client->fetchWatchedShows();
    }

    public function testThrowsRuntimeExceptionOnInvalidJson(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('not-json', ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]));
        $client = new TraktApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trakt API returned invalid JSON.');

        $client->fetchWatchedShows();
    }

    public function testParsesRatingsIntoStructuredShape(): void
    {
        $client = new TraktApiClient($this->ratingsHttpClient(), $this->tokenProvider(), self::CLIENT_ID);

        $ratings = $client->fetchRatings();

        self::assertSame([['traktId' => 1, 'rating' => 9]], $ratings['shows']);
        self::assertSame([['traktId' => 1, 'seasonNumber' => 1, 'rating' => 8]], $ratings['seasons']);
        self::assertSame([['traktId' => 1, 'seasonNumber' => 1, 'episodeNumber' => 2, 'rating' => 10]], $ratings['episodes']);
    }

    public function testSkipsRatingsWithoutTraktIdOrOutOfRange(): void
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url): MockResponse {
            $body = str_contains($url, '/sync/ratings/shows')
                ? json_encode([
                    ['rating' => 9, 'show' => ['ids' => ['slug' => 'no-trakt-id']]],
                    ['rating' => 0, 'show' => ['ids' => ['trakt' => 5]]],
                    ['rating' => 7, 'show' => ['ids' => ['trakt' => 6]]],
                ])
                : '[]';

            return new MockResponse((string) $body);
        });
        $client = new TraktApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        $ratings = $client->fetchRatings();

        self::assertSame([['traktId' => 6, 'rating' => 7]], $ratings['shows']);
        self::assertSame([], $ratings['seasons']);
        self::assertSame([], $ratings['episodes']);
    }

    private function ratingsHttpClient(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url): MockResponse {
            $body = match (true) {
                str_contains($url, '/sync/ratings/shows') => json_encode([
                    ['rating' => 9, 'show' => ['title' => 'Breaking Bad', 'ids' => ['trakt' => 1]]],
                ]),
                str_contains($url, '/sync/ratings/seasons') => json_encode([
                    ['rating' => 8, 'season' => ['number' => 1], 'show' => ['ids' => ['trakt' => 1]]],
                ]),
                str_contains($url, '/sync/ratings/episodes') => json_encode([
                    ['rating' => 10, 'episode' => ['season' => 1, 'number' => 2], 'show' => ['ids' => ['trakt' => 1]]],
                ]),
                default => '[]',
            };

            return new MockResponse((string) $body);
        });
    }
}
