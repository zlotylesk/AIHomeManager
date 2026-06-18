<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Module\YouTubeProgress\Application\DTO\VideoMetadata;
use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\Entity\WatchSession;
use App\Module\YouTubeProgress\Domain\Port\YouTubePlaylistReaderInterface;
use App\Module\YouTubeProgress\Domain\Port\YouTubePlaylistWriterInterface;
use App\Module\YouTubeProgress\Domain\ValueObject\ChannelName;
use App\Module\YouTubeProgress\Domain\ValueObject\VideoDuration;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use App\Module\YouTubeProgress\Infrastructure\Persistence\DoctrineVideoRepository;
use App\Module\YouTubeProgress\Infrastructure\Persistence\DoctrineWatchSessionRepository;
use App\Tests\Support\AuthenticatedApiTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class YouTubeProgressControllerTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;
    private Connection $connection;
    private DoctrineVideoRepository $videos;
    private DoctrineWatchSessionRepository $sessions;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();
        $this->videos = new DoctrineVideoRepository($em);
        $this->sessions = new DoctrineWatchSessionRepository($this->connection);

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $this->connection->executeStatement('TRUNCATE TABLE watch_session_videos');
        $this->connection->executeStatement('TRUNCATE TABLE watch_sessions');
        $this->connection->executeStatement('TRUNCATE TABLE videos');
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function seedVideo(
        string $id,
        string $title,
        string $channel,
        int $durationSeconds,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $watchedAt = null,
    ): void {
        $video = Video::fromYouTube(
            new YoutubeVideoId($id),
            $title,
            new ChannelName($channel),
            new VideoDuration($durationSeconds),
            new DateTimeImmutable('2026-06-01 10:00:00'),
        );
        if (null !== $startedAt) {
            $video->markStarted($startedAt);
        }
        if (null !== $watchedAt) {
            $video->markWatched($watchedAt);
        }

        $this->videos->save($video);
    }

    /**
     * @param list<string> $videoIds
     */
    private function seedSession(array $videoIds, int $totalDurationSeconds): WatchSession
    {
        $session = WatchSession::create(
            array_map(static fn (string $id): YoutubeVideoId => new YoutubeVideoId($id), $videoIds),
            $totalDurationSeconds,
            new DateTimeImmutable('2026-06-07 12:00:00'),
        );
        $this->sessions->save($session);

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function watchlistById(): array
    {
        $this->client->request('GET', '/api/youtube-progress/watchlist');
        $byId = [];
        foreach ($this->decode()['videos'] as $video) {
            $byId[$video['youtubeId']] = $video;
        }

        return $byId;
    }

    public function testGetWatchlistReturnsAllVideosWithStatus(): void
    {
        $this->seedVideo('vidpool0001', 'Pool video', 'Channel A', 600);
        $this->seedVideo('vidstart001', 'Started video', 'Channel A', 300, new DateTimeImmutable('2026-06-05 18:00:00'));
        $this->seedVideo('vidwatch001', 'Watched video', 'Channel B', 900, null, new DateTimeImmutable('2026-06-06 20:00:00'));

        $byId = $this->watchlistById();

        self::assertCount(3, $byId);
        self::assertSame('split-pool', $byId['vidpool0001']['status']);
        self::assertSame('started', $byId['vidstart001']['status']);
        self::assertSame('watched', $byId['vidwatch001']['status']);
        self::assertSame('Pool video', $byId['vidpool0001']['title']);
        self::assertSame('Channel A', $byId['vidpool0001']['channel']);
        self::assertSame(600, $byId['vidpool0001']['durationSeconds']);
    }

    public function testGetSessionsReturnsActiveSessionsWithVideos(): void
    {
        $this->seedVideo('vidaaaa0001', 'First', 'Channel A', 600);
        $this->seedVideo('vidbbbb0002', 'Second', 'Channel A', 300);
        $this->seedSession(['vidaaaa0001', 'vidbbbb0002'], 900);

        $this->client->request('GET', '/api/youtube-progress/sessions');

        self::assertResponseIsSuccessful();
        $data = $this->decode();
        self::assertCount(1, $data['sessions']);

        $session = $data['sessions'][0];
        self::assertSame(900, $session['totalDurationSeconds']);
        self::assertNull($session['youtubePlaylistId']);
        self::assertCount(2, $session['videos']);
        self::assertSame('First', $session['videos'][0]['title']);
        self::assertSame('Second', $session['videos'][1]['title']);
    }

    public function testSyncImportsVideosAndRegeneratesSessions(): void
    {
        $reader = new readonly class implements YouTubePlaylistReaderInterface {
            public function fetchPlaylistVideos(string $playlistId): array
            {
                return [
                    new VideoMetadata('vidsync0001', 'Sync One', 'Channel A', 600, new DateTimeImmutable('2026-06-01 10:00:00')),
                    new VideoMetadata('vidsync0002', 'Sync Two', 'Channel A', 300, new DateTimeImmutable('2026-06-01 11:00:00')),
                ];
            }
        };
        static::getContainer()->set(YouTubePlaylistReaderInterface::class, $reader);

        $this->client->request('POST', '/api/youtube-progress/sync');

        self::assertResponseIsSuccessful();
        $data = $this->decode();
        self::assertSame(2, $data['videos_count']);
        self::assertGreaterThanOrEqual(1, $data['sessions_count']);
    }

    public function testMarkStartedMovesVideoOutOfPool(): void
    {
        $this->seedVideo('vidstart001', 'Started video', 'Channel A', 300);

        $this->client->request('POST', '/api/youtube-progress/videos/vidstart001/start');
        self::assertResponseStatusCodeSame(204);

        $byId = $this->watchlistById();
        self::assertSame('started', $byId['vidstart001']['status']);
        self::assertNotNull($byId['vidstart001']['startedAt']);
    }

    public function testMarkWatchedMarksVideoWatched(): void
    {
        $this->seedVideo('vidwatch001', 'Watched video', 'Channel A', 300);

        $this->client->request('POST', '/api/youtube-progress/videos/vidwatch001/watched');
        self::assertResponseStatusCodeSame(204);

        $byId = $this->watchlistById();
        self::assertSame('watched', $byId['vidwatch001']['status']);
        self::assertNotNull($byId['vidwatch001']['watchedAt']);
    }

    public function testPushSessionRecordsPlaylistId(): void
    {
        $writer = new class implements YouTubePlaylistWriterInterface {
            public function createPlaylist(string $name, bool $private = true): string
            {
                return 'PL_created_123';
            }

            public function addVideosToPlaylist(string $playlistId, array $videoIds): void
            {
            }
        };
        static::getContainer()->set(YouTubePlaylistWriterInterface::class, $writer);

        $this->seedVideo('vidpush0001', 'Push One', 'Channel A', 600);
        $session = $this->seedSession(['vidpush0001'], 600);

        $this->client->request('POST', '/api/youtube-progress/sessions/'.$session->id()->value.'/push-to-youtube');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/youtube-progress/sessions');
        $data = $this->decode();
        self::assertSame('PL_created_123', $data['sessions'][0]['youtubePlaylistId']);
    }

    public function testMarkStartedReturns404ForUnknownVideo(): void
    {
        $this->client->request('POST', '/api/youtube-progress/videos/unknownvideo/start');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSyncReturns400IfPlaylistIdNotConfigured(): void
    {
        $original = $_ENV['YOUTUBE_WATCHLIST_PLAYLIST_ID'] ?? null;
        self::ensureKernelShutdown();
        $_ENV['YOUTUBE_WATCHLIST_PLAYLIST_ID'] = '';
        $_SERVER['YOUTUBE_WATCHLIST_PLAYLIST_ID'] = '';

        try {
            $client = static::createClient();
            $this->authenticate($client);
            $client->request('POST', '/api/youtube-progress/sync');

            self::assertResponseStatusCodeSame(400);
        } finally {
            $_ENV['YOUTUBE_WATCHLIST_PLAYLIST_ID'] = $original ?? 'PLtestwatchlist';
            $_SERVER['YOUTUBE_WATCHLIST_PLAYLIST_ID'] = $original ?? 'PLtestwatchlist';
        }
    }

    public function testIndexRendersTwigPage(): void
    {
        $this->client->request('GET', '/youtube-progress');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/html; charset=UTF-8');
        self::assertSelectorExists('nav.navbar');
        self::assertSelectorExists('#youtube-progress-watchlist');
        self::assertSelectorExists('#youtube-progress-sessions');
    }
}
