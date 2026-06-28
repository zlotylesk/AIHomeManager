<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\YouTubeProgress\Application;

use App\Module\YouTubeProgress\Application\Command\SyncWatchlist;
use App\Module\YouTubeProgress\Application\Handler\SyncWatchlistHandler;
use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\Port\YouTubePlaylistReaderInterface;
use App\Module\YouTubeProgress\Domain\ReadModel\VideoMetadata;
use App\Module\YouTubeProgress\Domain\ValueObject\ChannelName;
use App\Module\YouTubeProgress\Domain\ValueObject\VideoDuration;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use App\Module\YouTubeProgress\Infrastructure\Persistence\DoctrineVideoRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SyncWatchlistHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DoctrineVideoRepository $videos;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->videos = new DoctrineVideoRepository($this->em);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE videos');
    }

    /**
     * @param list<VideoMetadata> $metadata
     */
    private function handlerReturning(array $metadata): SyncWatchlistHandler
    {
        $reader = new readonly class($metadata) implements YouTubePlaylistReaderInterface {
            /** @param list<VideoMetadata> $metadata */
            public function __construct(private array $metadata)
            {
            }

            public function fetchPlaylistVideos(string $playlistId): array
            {
                return $this->metadata;
            }
        };

        return new SyncWatchlistHandler($reader, $this->videos);
    }

    private function metadata(string $id, string $title, string $channel, int $durationSeconds): VideoMetadata
    {
        return new VideoMetadata(
            youtubeId: $id,
            title: $title,
            channel: $channel,
            durationSeconds: $durationSeconds,
            publishedAt: new DateTimeImmutable('2026-06-01 10:00:00'),
        );
    }

    public function testNewVideosAreUpsertedIntoSplitPool(): void
    {
        $handler = $this->handlerReturning([
            $this->metadata('vid00000001', 'First', 'Channel A', 600),
            $this->metadata('vid00000002', 'Second', 'Channel B', 300),
            $this->metadata('vid00000003', 'Third', 'Channel A', 900),
        ]);

        $handler(new SyncWatchlist('PL_test'));
        $this->em->clear();

        $all = $this->videos->findAll();
        self::assertCount(3, $all);
        foreach ($all as $video) {
            self::assertTrue($video->isInSplitPool());
        }
    }

    public function testExistingVideoRefreshesMetadataButPreservesTimestamps(): void
    {
        $startedAt = new DateTimeImmutable('2026-06-05 18:00:00');
        $video = Video::fromYouTube(
            new YoutubeVideoId('vid00000001'),
            'Old title',
            new ChannelName('Channel A'),
            new VideoDuration(600),
            new DateTimeImmutable('2026-06-01 10:00:00'),
        );
        $video->markStarted($startedAt);
        $this->videos->save($video);
        $this->em->clear();

        $handler = $this->handlerReturning([
            $this->metadata('vid00000001', 'New title', 'Channel A', 1200),
        ]);
        $handler(new SyncWatchlist('PL_test'));
        $this->em->clear();

        $loaded = $this->videos->findByYoutubeId(new YoutubeVideoId('vid00000001'));
        self::assertNotNull($loaded);
        self::assertSame('New title', $loaded->title());
        self::assertSame(1200, $loaded->duration()->toSeconds());

        self::assertEquals($startedAt, $loaded->startedAt());
        self::assertFalse($loaded->isInSplitPool());
    }

    public function testEmptyPlaylistDoesNothing(): void
    {
        $handler = $this->handlerReturning([]);

        $handler(new SyncWatchlist('PL_test'));
        $this->em->clear();

        self::assertSame([], $this->videos->findAll());
    }

    public function testIdempotentRunDoesNotCreateDuplicates(): void
    {
        $metadata = [
            $this->metadata('vid00000001', 'First', 'Channel A', 600),
            $this->metadata('vid00000002', 'Second', 'Channel B', 300),
            $this->metadata('vid00000003', 'Third', 'Channel A', 900),
        ];

        $this->handlerReturning($metadata)(new SyncWatchlist('PL_test'));
        $this->em->clear();
        $this->handlerReturning($metadata)(new SyncWatchlist('PL_test'));
        $this->em->clear();

        self::assertCount(3, $this->videos->findAll());
    }
}
