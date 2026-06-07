<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\YouTubeProgress\Application;

use App\Module\YouTubeProgress\Application\Command\RegenerateSessions;
use App\Module\YouTubeProgress\Application\Handler\RegenerateSessionsHandler;
use App\Module\YouTubeProgress\Application\Service\WatchSessionSplitter;
use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\Entity\WatchSession;
use App\Module\YouTubeProgress\Domain\Repository\WatchSessionRepositoryInterface;
use App\Module\YouTubeProgress\Domain\ValueObject\ChannelName;
use App\Module\YouTubeProgress\Domain\ValueObject\VideoDuration;
use App\Module\YouTubeProgress\Domain\ValueObject\WatchSessionId;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use App\Module\YouTubeProgress\Infrastructure\Persistence\DoctrineVideoRepository;
use App\Module\YouTubeProgress\Infrastructure\Persistence\DoctrineWatchSessionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RegenerateSessionsHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DoctrineVideoRepository $videos;
    private DoctrineWatchSessionRepository $sessions;
    private WatchSessionSplitter $splitter;
    private RegenerateSessionsHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->videos = new DoctrineVideoRepository($this->em);
        $this->sessions = new DoctrineWatchSessionRepository($this->em->getConnection());
        $this->splitter = new WatchSessionSplitter();
        $this->handler = new RegenerateSessionsHandler(
            $this->videos,
            $this->sessions,
            $this->splitter,
            $this->em,
            new NullLogger(),
        );

        $this->sessions->deleteAll();
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE videos');
    }

    private function saveVideoInPool(string $id, string $channel, int $durationSeconds): void
    {
        $this->videos->save(Video::fromYouTube(
            new YoutubeVideoId($id),
            'Title '.$id,
            new ChannelName($channel),
            new VideoDuration($durationSeconds),
            new DateTimeImmutable('2026-06-01 10:00:00'),
        ));
    }

    public function testRegenerateOnEmptyPoolProducesNoSessions(): void
    {
        ($this->handler)(new RegenerateSessions());

        self::assertSame([], $this->sessions->findAll());
    }

    public function testRegenerateOnSinglePoolProducesOneSession(): void
    {
        $this->saveVideoInPool('singlevideo', 'Channel A', 600);

        ($this->handler)(new RegenerateSessions());

        $sessions = $this->sessions->findAll();
        self::assertCount(1, $sessions);
        self::assertCount(1, $sessions[0]->videoIds());
        self::assertSame('singlevideo', $sessions[0]->videoIds()[0]->value());
    }

    public function testRegenerateReplacesExistingSessions(): void
    {
        $stale1 = WatchSession::create([new YoutubeVideoId('stale000001')], 100, new DateTimeImmutable('2026-05-01'));
        $stale2 = WatchSession::create([new YoutubeVideoId('stale000002')], 100, new DateTimeImmutable('2026-05-02'));
        $this->sessions->save($stale1);
        $this->sessions->save($stale2);

        $this->saveVideoInPool('freshvideo1', 'Channel A', 600);
        $this->saveVideoInPool('freshvideo2', 'Channel A', 600);
        $this->saveVideoInPool('freshvideo3', 'Channel A', 600);

        ($this->handler)(new RegenerateSessions());

        $ids = array_map(static fn (WatchSession $s): string => $s->id()->toString(), $this->sessions->findAll());

        self::assertNotContains($stale1->id()->toString(), $ids);
        self::assertNotContains($stale2->id()->toString(), $ids);
        self::assertNotEmpty($ids);

        // Every fresh video ended up in exactly one regenerated session.
        $videoIds = [];
        foreach ($this->sessions->findAll() as $session) {
            foreach ($session->videoIds() as $videoId) {
                $videoIds[] = $videoId->value();
            }
        }
        sort($videoIds);
        self::assertSame(['freshvideo1', 'freshvideo2', 'freshvideo3'], $videoIds);
    }

    public function testRegenerateRespectsSplitterAlgorithm(): void
    {
        // Two channels, all videos fitting one 1800s session. Splitter orders
        // channels by video count DESC (Beta: 2 before Alpha: 1) and videos
        // within a channel by duration ASC.
        $this->saveVideoInPool('beta0000600', 'Beta', 600);
        $this->saveVideoInPool('beta0000300', 'Beta', 300);
        $this->saveVideoInPool('alfa0000400', 'Alpha', 400);

        ($this->handler)(new RegenerateSessions());

        $sessions = $this->sessions->findAll();
        self::assertCount(1, $sessions);

        $order = array_map(static fn (YoutubeVideoId $v): string => $v->value(), $sessions[0]->videoIds());
        self::assertSame(['beta0000300', 'beta0000600', 'alfa0000400'], $order);
    }

    public function testRollbackOnFailureKeepsExistingSessions(): void
    {
        $stale1 = WatchSession::create([new YoutubeVideoId('stale000001')], 100, new DateTimeImmutable('2026-05-01'));
        $stale2 = WatchSession::create([new YoutubeVideoId('stale000002')], 100, new DateTimeImmutable('2026-05-02'));
        $this->sessions->save($stale1);
        $this->sessions->save($stale2);

        $this->saveVideoInPool('freshvideo1', 'Channel A', 600);

        // The splitter is final and cannot be mocked, so inject the failure at
        // the save boundary instead: deleteAll still runs inside the handler's
        // transaction, so a rollback must restore the two pre-existing sessions.
        $failingSessions = new readonly class($this->sessions) implements WatchSessionRepositoryInterface {
            public function __construct(private WatchSessionRepositoryInterface $inner)
            {
            }

            public function save(WatchSession $session): void
            {
                throw new RuntimeException('persistence boom');
            }

            public function findById(WatchSessionId $id): ?WatchSession
            {
                return $this->inner->findById($id);
            }

            public function findAll(): array
            {
                return $this->inner->findAll();
            }

            public function deleteAll(): void
            {
                $this->inner->deleteAll();
            }
        };

        $handler = new RegenerateSessionsHandler(
            $this->videos,
            $failingSessions,
            $this->splitter,
            $this->em,
            new NullLogger(),
        );

        try {
            $handler(new RegenerateSessions());
            self::fail('Expected RuntimeException to bubble up');
        } catch (RuntimeException $e) {
            self::assertSame('persistence boom', $e->getMessage());
        }

        $ids = array_map(static fn (WatchSession $s): string => $s->id()->toString(), $this->sessions->findAll());
        self::assertContains($stale1->id()->toString(), $ids);
        self::assertContains($stale2->id()->toString(), $ids);
        self::assertCount(2, $ids);
    }
}
