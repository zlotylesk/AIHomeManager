<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Infrastructure;

use App\Module\Podcasts\Infrastructure\External\SpotifyPodcastApiClient;
use App\Module\Podcasts\Infrastructure\External\SpotifyTokenProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(SpotifyPodcastApiClient::class)]
final class SpotifyPodcastApiClientTest extends TestCase
{
    public function testMapsStartedAndFinishedEpisodesOntoTheReadModel(): void
    {
        $client = $this->clientFor([
            $this->savedShowsResponse(),
            $this->episodesResponse(),
            $this->nothingPlayingResponse(),
        ]);

        $episodes = $client->fetchListenedEpisodes();

        self::assertCount(2, $episodes, 'Only the started and the finished episode qualify.');

        $started = $episodes[0];
        self::assertSame('show-1', $started->podcastExternalId);
        self::assertSame('Radio Nowak', $started->podcastTitle);
        self::assertSame('ep-started', $started->episodeExternalId);
        self::assertSame('Halfway through', $started->episodeTitle);
        self::assertSame('Studio Nowak', $started->publisher);
        self::assertSame('https://i.scdn.co/image/show-1.jpg', $started->coverUrl);
        self::assertSame(1_800_000, $started->durationMs);
        self::assertSame('2026-07-01', $started->publishedAt?->format('Y-m-d'));
        self::assertSame(900_000, $started->progress->resumePositionMs());
        self::assertFalse($started->progress->fullyPlayed());

        $finished = $episodes[1];
        self::assertSame('ep-finished', $finished->episodeExternalId);
        self::assertTrue($finished->progress->fullyPlayed());
    }

    /**
     * An untouched episode has a zero resume point. Those are the overwhelming
     * majority of a subscription's back catalogue — reporting them would turn
     * "what I listened to" into "everything I am subscribed to".
     */
    public function testSkipsEpisodesTheUserNeverStarted(): void
    {
        $client = $this->clientFor([
            $this->savedShowsResponse(),
            $this->episodesResponse(),
            $this->nothingPlayingResponse(),
        ]);

        $ids = array_map(
            static fn ($episode): string => $episode->episodeExternalId,
            $client->fetchListenedEpisodes()
        );

        self::assertNotContains('ep-untouched', $ids);
    }

    /**
     * The currently-playing endpoint is the only place Spotify names a real
     * moment, so the episode it reports gets that timestamp instead of the
     * observation time.
     */
    public function testDatesTheCurrentlyPlayingEpisodeWithSpotifysTimestamp(): void
    {
        $playedAtMs = 1_770_000_000_000;

        $client = $this->clientFor([
            $this->savedShowsResponse(),
            $this->episodesResponse(),
            new MockResponse((string) json_encode([
                'timestamp' => $playedAtMs,
                'is_playing' => true,
                'item' => ['id' => 'ep-started', 'type' => 'episode'],
            ])),
        ]);

        $episodes = $client->fetchListenedEpisodes();

        self::assertSame(
            intdiv($playedAtMs, 1000),
            $episodes[0]->listenedAt->getTimestamp(),
            'The playing episode carries the reported moment.'
        );
        self::assertNotSame(
            $episodes[0]->listenedAt->getTimestamp(),
            $episodes[1]->listenedAt->getTimestamp(),
            'Everything else keeps the observation time.'
        );
    }

    /**
     * Spotify answers 204 with an empty body when the player is idle. Decoding
     * that as JSON would throw, so the adapter must treat it as "nothing to add".
     */
    public function testSurvivesAnIdlePlayerAnsweringWithNoContent(): void
    {
        $client = $this->clientFor([
            $this->savedShowsResponse(),
            $this->episodesResponse(),
            new MockResponse('', ['http_code' => 204]),
        ]);

        self::assertCount(2, $client->fetchListenedEpisodes());
    }

    /**
     * A subscription can 404 on its episode list — pulled from the catalogue, or
     * region-restricted. Letting that abort the sweep would stop the user's
     * listening syncing entirely until they unsubscribed, so the show is skipped
     * and the rest still reports.
     */
    public function testSkipsAShowWhoseEpisodesCannotBeRead(): void
    {
        $client = $this->clientFor([
            $this->twoSavedShowsResponse(),
            new MockResponse('{"error":{"status":404}}', ['http_code' => 404]),
            $this->episodesResponse(),
            $this->nothingPlayingResponse(),
        ]);

        $episodes = $client->fetchListenedEpisodes();

        self::assertCount(2, $episodes, 'The readable show still reports its listens.');
        self::assertSame('show-2', $episodes[0]->podcastExternalId);
    }

    /**
     * The per-show tolerance above must not extend to the call that lists the
     * subscriptions: if that fails there is nothing to sweep, and silently
     * returning "no listening" would look exactly like a quiet week.
     */
    public function testStillFailsLoudlyWhenTheSubscriptionListCannotBeRead(): void
    {
        $client = $this->clientFor([new MockResponse('{"error":{"status":503}}', ['http_code' => 503])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Spotify API returned HTTP 503');

        $client->fetchListenedEpisodes();
    }

    public function testFailsLoudlyWhenSpotifyWasNeverConnected(): void
    {
        $client = new SpotifyPodcastApiClient(
            new MockHttpClient([]),
            $this->tokenProviderReturning(null),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Spotify account not connected.');

        $client->fetchListenedEpisodes();
    }

    public function testMapsATransportFailureOntoAReadableException(): void
    {
        $client = $this->clientFor([
            new MockResponse((string) json_encode([]), ['error' => new TransportException('network down')]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Spotify API unavailable.');

        $client->fetchListenedEpisodes();
    }

    public function testRejectsAnErrorStatusFromTheApi(): void
    {
        $client = $this->clientFor([new MockResponse('{"error":{"status":429}}', ['http_code' => 429])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Spotify API returned HTTP 429');

        $client->fetchListenedEpisodes();
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function clientFor(array $responses): SpotifyPodcastApiClient
    {
        return new SpotifyPodcastApiClient(
            new MockHttpClient($responses),
            $this->tokenProviderReturning('access-token'),
        );
    }

    private function tokenProviderReturning(?string $token): SpotifyTokenProvider
    {
        $repository = new InMemorySpotifyTokenRepository(
            null === $token
                ? null
                : ['access_token' => $token, 'expires_in' => 3600, 'created_at' => time()]
        );

        return new SpotifyTokenProvider($repository, new MockHttpClient([]), 'id', 'secret');
    }

    private function savedShowsResponse(): MockResponse
    {
        return new MockResponse((string) json_encode([
            'items' => [
                [
                    'added_at' => '2026-01-01T00:00:00Z',
                    'show' => [
                        'id' => 'show-1',
                        'name' => 'Radio Nowak',
                        'publisher' => 'Studio Nowak',
                        'images' => [['url' => 'https://i.scdn.co/image/show-1.jpg', 'width' => 640]],
                    ],
                ],
            ],
            'next' => null,
        ]));
    }

    /**
     * A broken show first, a readable one second — so the assertion proves the
     * sweep carried on rather than that it never reached the failure.
     */
    private function twoSavedShowsResponse(): MockResponse
    {
        return new MockResponse((string) json_encode([
            'items' => [
                ['show' => ['id' => 'show-gone', 'name' => 'Pulled from the catalogue']],
                ['show' => ['id' => 'show-2', 'name' => 'Radio Nowak', 'publisher' => 'Studio Nowak']],
            ],
            'next' => null,
        ]));
    }

    private function episodesResponse(): MockResponse
    {
        return new MockResponse((string) json_encode([
            'items' => [
                [
                    'id' => 'ep-started',
                    'name' => 'Halfway through',
                    'duration_ms' => 1_800_000,
                    'release_date' => '2026-07-01',
                    'release_date_precision' => 'day',
                    'resume_point' => ['fully_played' => false, 'resume_position_ms' => 900_000],
                ],
                [
                    'id' => 'ep-finished',
                    'name' => 'Heard it all',
                    'duration_ms' => 2_400_000,
                    'release_date' => '2026-06-24',
                    'release_date_precision' => 'day',
                    'resume_point' => ['fully_played' => true, 'resume_position_ms' => 0],
                ],
                [
                    'id' => 'ep-untouched',
                    'name' => 'Never opened',
                    'duration_ms' => 1_200_000,
                    'release_date' => '2026',
                    'release_date_precision' => 'year',
                    'resume_point' => ['fully_played' => false, 'resume_position_ms' => 0],
                ],
            ],
            'next' => null,
        ]));
    }

    private function nothingPlayingResponse(): MockResponse
    {
        return new MockResponse((string) json_encode(['is_playing' => false, 'item' => null]));
    }
}
