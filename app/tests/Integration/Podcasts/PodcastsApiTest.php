<?php

declare(strict_types=1);

namespace App\Tests\Integration\Podcasts;

use App\Module\Podcasts\Application\Command\PollPodcastListens;
use App\Module\Podcasts\Infrastructure\Persistence\SpotifyTokenRepositoryInterface;
use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Pins the Podcasts HTTP contract byte-for-byte: the read shapes the frontend
 * (HMAI-327) will consume, and the sync trigger's 202/409 split.
 */
final class PodcastsApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private const string UNKNOWN_UUID = '00000000-0000-0000-0000-000000000000';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['podcast_listening_sessions', 'podcast_episodes', 'podcasts', 'spotify_oauth_tokens'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testListIsEmptyWhenNothingIsFollowed(): void
    {
        $this->client->request('GET', '/api/podcasts');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonResponse($this->client));
    }

    public function testListCarriesCountersAndTheLatestListen(): void
    {
        $this->seedShow();

        $this->client->request('GET', '/api/podcasts');

        self::assertResponseIsSuccessful();
        $body = $this->jsonResponse($this->client);
        self::assertCount(1, $body);

        self::assertSame([
            'id' => 'pod-1',
            'title' => 'Radio Nowak',
            'publisher' => 'Studio Nowak',
            'coverUrl' => 'https://example.test/cover.jpg',
            'description' => 'Rozmowy.',
            'episodeCount' => 3,
            'listenedEpisodeCount' => 2,
            'lastListenedAt' => '2026-07-20T19:00:00+00:00',
            'createdAt' => '2026-07-01T10:00:00+00:00',
        ], $body[0]);
    }

    /**
     * A show nobody has listened to must still list, with honest zeroes rather
     * than being dropped by a join.
     */
    public function testAShowWithNoListeningStillLists(): void
    {
        $this->insertPodcast('pod-quiet', 'Cisza');

        $this->client->request('GET', '/api/podcasts');

        $body = $this->jsonResponse($this->client);
        self::assertCount(1, $body);
        self::assertSame(0, $body[0]['episodeCount']);
        self::assertSame(0, $body[0]['listenedEpisodeCount']);
        self::assertNull($body[0]['lastListenedAt']);
    }

    /**
     * Shows with recent listening come first; never-listened ones sort last
     * rather than jumping to the top on a NULL.
     */
    public function testListOrdersRecentlyListenedFirstAndNeverListenedLast(): void
    {
        $this->seedShow();
        $this->insertPodcast('pod-quiet', 'Cisza');

        $this->client->request('GET', '/api/podcasts');

        $ids = array_column($this->jsonResponse($this->client), 'id');
        self::assertSame(['pod-1', 'pod-quiet'], $ids);
    }

    public function testDetailFlattensTheShowAndCarriesEpisodesAndSessions(): void
    {
        $this->seedShow();

        $this->client->request('GET', '/api/podcasts/pod-1');

        self::assertResponseIsSuccessful();
        $body = $this->jsonResponse($this->client);

        self::assertSame('Radio Nowak', $body['title'], 'The show fields are flattened, not nested under an envelope.');
        self::assertSame(3, $body['episodeCount']);

        self::assertCount(3, $body['episodes']);
        self::assertCount(3, $body['sessions']);
    }

    /**
     * The furthest progress ever recorded wins — an episode listened to across
     * two days must not read as whatever the last partial sitting left behind.
     */
    public function testEpisodeProgressAggregatesAcrossSessions(): void
    {
        $this->seedShow();

        $this->client->request('GET', '/api/podcasts/pod-1');
        $episodes = array_column($this->jsonResponse($this->client)['episodes'], null, 'id');

        self::assertTrue($episodes['ep-1']['listened']);
        self::assertTrue($episodes['ep-1']['fullyPlayed'], 'Finished on the second day.');
        self::assertSame(1_700_000, $episodes['ep-1']['resumePositionMs'], 'The furthest point, not the latest.');

        self::assertTrue($episodes['ep-2']['listened']);
        self::assertFalse($episodes['ep-2']['fullyPlayed']);

        self::assertFalse($episodes['ep-3']['listened'], 'Never opened.');
        self::assertSame(0, $episodes['ep-3']['resumePositionMs']);
    }

    public function testSessionsAreNewestFirstAndNameTheirEpisode(): void
    {
        $this->seedShow();

        $this->client->request('GET', '/api/podcasts/pod-1');
        $sessions = $this->jsonResponse($this->client)['sessions'];

        self::assertSame('2026-07-20T19:00:00+00:00', $sessions[0]['listenedAt']);
        self::assertSame('Odcinek pierwszy', $sessions[0]['episodeTitle']);
        self::assertSame('ep-1', $sessions[0]['episodeId']);
    }

    public function testDetailReturns404ForAnUnknownShow(): void
    {
        $this->client->request('GET', '/api/podcasts/'.self::UNKNOWN_UUID);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'Podcast not found.'], $this->jsonResponse($this->client));
    }

    public function testSyncReturns409WhenSpotifyIsNotConnected(): void
    {
        $this->client->request('POST', '/api/podcasts/sync');

        self::assertResponseStatusCodeSame(409);
        $body = $this->jsonResponse($this->client);
        self::assertSame('/auth/spotify', $body['authUrl']);

        self::assertSame([], $this->asyncTransport()->getSent(), 'Nothing may be dispatched when the source is unreachable.');
    }

    public function testSyncReturns202AndOffloadsTheSweepWhenConnected(): void
    {
        // Stub the token port so the connectivity check passes without a real
        // OAuth token — the stored payload is encrypted, so a hand-written row
        // would fail to decrypt rather than read as "connected". The sweep is
        // async-routed (in-memory here), so nothing runs inline.
        $this->client->disableReboot();
        $tokens = $this->createStub(SpotifyTokenRepositoryInterface::class);
        $tokens->method('get')->willReturn(['access_token' => 'stub-token']);
        self::getContainer()->set(SpotifyTokenRepositoryInterface::class, $tokens);

        $this->client->request('POST', '/api/podcasts/sync');

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['status' => 'sync_started'], $this->jsonResponse($this->client));

        $sent = array_filter(
            $this->asyncTransport()->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof PollPodcastListens,
        );
        self::assertCount(1, $sent, 'The sweep runs on the worker, never inline in the request.');
    }

    public function testTheVersionedPathAndTheAliasAgree(): void
    {
        $this->seedShow();

        $this->client->request('GET', '/api/v1/podcasts');
        $versioned = $this->jsonResponse($this->client);

        $this->client->request('GET', '/api/podcasts');
        $alias = $this->jsonResponse($this->client);

        self::assertSame($versioned, $alias);
    }

    public function testTheApiRequiresAKey(): void
    {
        $this->client->setServerParameter('HTTP_X_API_KEY', 'wrong-key');
        $this->client->request('GET', '/api/podcasts');

        self::assertResponseStatusCodeSame(401);
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    /**
     * One show, three episodes, three sessions: ep-1 listened on two days
     * (finishing on the second), ep-2 started once, ep-3 never opened.
     */
    private function seedShow(): void
    {
        $this->insertPodcast('pod-1', 'Radio Nowak', 'Studio Nowak', 'https://example.test/cover.jpg', 'Rozmowy.');

        $this->insertEpisode('ep-1', 'pod-1', 'Odcinek pierwszy', '2026-07-01 06:00:00', 1_800_000);
        $this->insertEpisode('ep-2', 'pod-1', 'Odcinek drugi', '2026-06-24 06:00:00', 2_400_000);
        $this->insertEpisode('ep-3', 'pod-1', 'Odcinek trzeci', null, 1_200_000);

        $this->insertSession('s-1', 'pod-1', 'ep-1', '2026-07-19 08:00:00', 600_000, false);
        $this->insertSession('s-2', 'pod-1', 'ep-1', '2026-07-20 19:00:00', 1_700_000, true);
        $this->insertSession('s-3', 'pod-1', 'ep-2', '2026-07-18 12:00:00', 300_000, false);
    }

    private function insertPodcast(
        string $id,
        string $title,
        ?string $publisher = null,
        ?string $coverUrl = null,
        ?string $description = null,
    ): void {
        $this->connection->insert('podcasts', [
            'id' => $id,
            'title' => $title,
            'publisher' => $publisher,
            'cover_url' => $coverUrl,
            'description' => $description,
            'created_at' => '2026-07-01 10:00:00',
        ]);
    }

    private function insertEpisode(
        string $id,
        string $podcastId,
        string $title,
        ?string $publishedAt,
        ?int $durationMs,
    ): void {
        $this->connection->insert('podcast_episodes', [
            'id' => $id,
            'podcast_id' => $podcastId,
            'title' => $title,
            'published_at' => $publishedAt,
            'duration_ms' => $durationMs,
            'created_at' => '2026-07-01 10:00:00',
        ]);
    }

    private function insertSession(
        string $id,
        string $podcastId,
        string $episodeId,
        string $listenedAt,
        int $resumePositionMs,
        bool $fullyPlayed,
    ): void {
        $this->connection->insert('podcast_listening_sessions', [
            'id' => $id,
            'podcast_id' => $podcastId,
            'episode_id' => $episodeId,
            'listened_at' => $listenedAt,
            'resume_position_ms' => $resumePositionMs,
            'fully_played' => $fullyPlayed ? 1 : 0,
            'dedup_hash' => hash('sha256', $id),
            'created_at' => '2026-07-20 20:00:00',
        ]);
    }
}
