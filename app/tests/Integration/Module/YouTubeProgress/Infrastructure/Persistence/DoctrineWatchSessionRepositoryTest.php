<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\YouTubeProgress\Infrastructure\Persistence;

use App\Module\YouTubeProgress\Domain\Entity\WatchSession;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use App\Module\YouTubeProgress\Infrastructure\Persistence\DoctrineWatchSessionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineWatchSessionRepositoryTest extends KernelTestCase
{
    private DoctrineWatchSessionRepository $repository;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(Connection::class);
        $this->repository = new DoctrineWatchSessionRepository($this->connection);

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $this->connection->executeStatement('TRUNCATE TABLE watch_session_videos');
        $this->connection->executeStatement('TRUNCATE TABLE watch_sessions');
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @param string[] $videoIdValues */
    private function makeSession(array $videoIdValues, int $totalDurationSeconds = 600, ?DateTimeImmutable $createdAt = null): WatchSession
    {
        $videoIds = array_map(static fn (string $v): YoutubeVideoId => new YoutubeVideoId($v), $videoIdValues);

        return WatchSession::create(
            $videoIds,
            $totalDurationSeconds,
            $createdAt ?? new DateTimeImmutable('2026-06-07 10:00:00'),
        );
    }

    public function testSaveAndFindByIdPreservesVideoOrder(): void
    {
        $session = $this->makeSession(['aaaaaaaaaaa', 'bbbbbbbbbbb', 'ccccccccccc']);

        $this->repository->save($session);

        $loaded = $this->repository->findById($session->id());

        self::assertNotNull($loaded);
        $values = array_map(static fn (YoutubeVideoId $id): string => $id->value(), $loaded->videoIds());
        self::assertSame(['aaaaaaaaaaa', 'bbbbbbbbbbb', 'ccccccccccc'], $values);
    }

    public function testFindAllReturnsSessionsOrderedByCreatedAtDesc(): void
    {
        $oldest = $this->makeSession(['aaaaaaaaaaa'], 100, new DateTimeImmutable('2026-06-05 08:00:00'));
        $middle = $this->makeSession(['bbbbbbbbbbb'], 200, new DateTimeImmutable('2026-06-06 08:00:00'));
        $newest = $this->makeSession(['ccccccccccc'], 300, new DateTimeImmutable('2026-06-07 08:00:00'));

        $this->repository->save($oldest);
        $this->repository->save($middle);
        $this->repository->save($newest);

        $all = $this->repository->findAll();

        self::assertCount(3, $all);
        self::assertSame(300, $all[0]->totalDurationSeconds());
        self::assertSame(200, $all[1]->totalDurationSeconds());
        self::assertSame(100, $all[2]->totalDurationSeconds());
    }

    public function testSavePushedSessionPreservesYoutubePlaylistId(): void
    {
        $session = $this->makeSession(['aaaaaaaaaaa', 'bbbbbbbbbbb']);
        $session->markPushedToYouTube('PLxxxYouTubePlaylist1');

        $this->repository->save($session);

        $loaded = $this->repository->findById($session->id());

        self::assertNotNull($loaded);
        self::assertSame('PLxxxYouTubePlaylist1', $loaded->youtubePlaylistId());
        self::assertTrue($loaded->isPushedToYouTube());
    }

    public function testDeleteAllRemovesAllSessionsAndJunctionRows(): void
    {
        $this->repository->save($this->makeSession(['aaaaaaaaaaa', 'bbbbbbbbbbb']));
        $this->repository->save($this->makeSession(['ccccccccccc', 'ddddddddddd', 'eeeeeeeeeee']));
        $this->repository->save($this->makeSession(['fffffffffff', 'ggggggggggg', 'hhhhhhhhhhh']));

        $this->repository->deleteAll();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM watch_sessions'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM watch_session_videos'));
    }

    public function testSaveOnExistingSessionReplacesVideos(): void
    {
        $original = $this->makeSession(['aaaaaaaaaaa', 'bbbbbbbbbbb']);
        $this->repository->save($original);

        $replacement = WatchSession::reconstitute(
            $original->id()->value,
            [
                new YoutubeVideoId('xxxxxxxxxxx'),
                new YoutubeVideoId('yyyyyyyyyyy'),
                new YoutubeVideoId('zzzzzzzzzzz'),
            ],
            $original->totalDurationSeconds(),
            $original->createdAt(),
            null,
        );

        $this->repository->save($replacement);

        $loaded = $this->repository->findById($original->id());

        self::assertNotNull($loaded);
        $values = array_map(static fn (YoutubeVideoId $id): string => $id->value(), $loaded->videoIds());
        self::assertSame(['xxxxxxxxxxx', 'yyyyyyyyyyy', 'zzzzzzzzzzz'], $values);

        $junctionCount = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM watch_session_videos WHERE watch_session_id = ?',
            [$original->id()->value],
        );
        self::assertSame(3, $junctionCount);
    }
}
