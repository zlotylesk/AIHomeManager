<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Infrastructure;

use App\Module\Movies\Infrastructure\External\TraktMoviesApiClient;
use App\Shared\Security\TraktTokenProviderInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TraktMoviesApiClientTest extends TestCase
{
    private const string CLIENT_ID = 'test-trakt-client-id';

    /**
     * @param array<string, mixed>|null $token
     */
    private function tokenProvider(?array $token = ['access_token' => 'access-123']): TraktTokenProviderInterface
    {
        $provider = $this->createStub(TraktTokenProviderInterface::class);
        $provider->method('get')->willReturn($token);

        return $provider;
    }

    /**
     * @return array<string, mixed>
     */
    private function watchedMovie(): array
    {
        return [
            'last_watched_at' => '2020-01-02T20:00:00.000Z',
            'movie' => [
                'title' => 'Blade Runner 2049',
                'year' => 2017,
                'ids' => ['trakt' => 6, 'slug' => 'blade-runner-2049-2017'],
            ],
        ];
    }

    public function testParsesWatchedMoviesIntoStructuredShape(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([$this->watchedMovie()])));
        $client = new TraktMoviesApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        $movies = $client->fetchWatchedMovies();

        self::assertCount(1, $movies);
        self::assertSame(6, $movies[0]['traktId']);
        self::assertSame('Blade Runner 2049', $movies[0]['title']);
        self::assertSame(2017, $movies[0]['year']);
        self::assertSame('2020-01-02T20:00:00.000Z', $movies[0]['lastWatchedAt']);
    }

    public function testReturnsEmptyArrayWhenNoMovies(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('[]'));
        $client = new TraktMoviesApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        self::assertSame([], $client->fetchWatchedMovies());
    }

    public function testSkipsMoviesWithoutTraktId(): void
    {
        $noId = $this->watchedMovie();
        unset($noId['movie']['ids']['trakt']);

        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([$noId, $this->watchedMovie()])));
        $client = new TraktMoviesApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        $movies = $client->fetchWatchedMovies();

        self::assertCount(1, $movies);
        self::assertSame(6, $movies[0]['traktId']);
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
        $client = new TraktMoviesApiClient($httpClient, $this->tokenProvider(['access_token' => 'access-123']), self::CLIENT_ID);

        $client->fetchWatchedMovies();

        self::assertSame('GET', $captured['method']);
        self::assertStringContainsString('api.trakt.tv/sync/watched/movies', $captured['url']);
        self::assertStringContainsString('extended=full', $captured['url']);
        self::assertStringContainsString('trakt-api-version: 2', $captured['headers']);
        self::assertStringContainsString('trakt-api-key: '.self::CLIENT_ID, $captured['headers']);
        self::assertStringContainsString('Authorization: Bearer access-123', $captured['headers']);
    }

    public function testThrowsWhenNoTokenStored(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('[]'));
        $client = new TraktMoviesApiClient($httpClient, $this->tokenProvider(null), self::CLIENT_ID);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trakt account not connected.');

        $client->fetchWatchedMovies();
    }

    public function testThrowsWhenClientIdIsBlank(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('[]'));
        $client = new TraktMoviesApiClient($httpClient, $this->tokenProvider(), '   ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trakt client ID not configured.');

        $client->fetchWatchedMovies();
    }

    public function testThrowsRuntimeExceptionOnTransportError(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        $client = new TraktMoviesApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trakt API unavailable.');

        $client->fetchWatchedMovies();
    }

    public function testParsesMovieRatingsIntoStructuredShape(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([
            ['rating' => 9, 'movie' => ['title' => 'Heat', 'ids' => ['trakt' => 6]]],
        ])));
        $client = new TraktMoviesApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        self::assertSame([['traktId' => 6, 'rating' => 9]], $client->fetchMovieRatings());
    }

    public function testSkipsRatingsWithoutTraktIdOrOutOfRange(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([
            ['rating' => 9, 'movie' => ['ids' => ['slug' => 'no-trakt-id']]],
            ['rating' => 0, 'movie' => ['ids' => ['trakt' => 5]]],
            ['rating' => 7, 'movie' => ['ids' => ['trakt' => 6]]],
        ])));
        $client = new TraktMoviesApiClient($httpClient, $this->tokenProvider(), self::CLIENT_ID);

        self::assertSame([['traktId' => 6, 'rating' => 7]], $client->fetchMovieRatings());
    }
}
