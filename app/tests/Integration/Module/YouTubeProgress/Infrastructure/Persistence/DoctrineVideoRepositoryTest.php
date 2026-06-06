<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\YouTubeProgress\Infrastructure\Persistence;

use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\ValueObject\ChannelName;
use App\Module\YouTubeProgress\Domain\ValueObject\VideoDuration;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use App\Module\YouTubeProgress\Infrastructure\Persistence\DoctrineVideoRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineVideoRepositoryTest extends KernelTestCase
{
    private DoctrineVideoRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineVideoRepository($this->em);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE videos');
    }

    private function makeVideo(string $id, string $title = 'Sample title', string $channel = 'Sample Channel', int $durationSeconds = 600): Video
    {
        return Video::fromYouTube(
            new YoutubeVideoId($id),
            $title,
            new ChannelName($channel),
            new VideoDuration($durationSeconds),
            new DateTimeImmutable('2026-06-01 10:00:00'),
        );
    }

    public function testSaveAndFindByYoutubeId(): void
    {
        $video = $this->makeVideo('dQw4w9WgXcQ');

        $this->repository->save($video);
        $this->em->clear();

        $loaded = $this->repository->findByYoutubeId(new YoutubeVideoId('dQw4w9WgXcQ'));

        self::assertNotNull($loaded);
        self::assertSame('dQw4w9WgXcQ', $loaded->id()->value());
        self::assertSame('Sample title', $loaded->title());
        self::assertSame('Sample Channel', $loaded->channel()->value());
        self::assertSame(600, $loaded->duration()->toSeconds());
        self::assertTrue($loaded->isInSplitPool());
    }

    public function testFindAllInSplitPoolFiltersOutMarkedVideos(): void
    {
        $plain = $this->makeVideo('aaaaaaaaaaa');

        $started = $this->makeVideo('bbbbbbbbbbb');
        $started->markStarted(new DateTimeImmutable('2026-06-02 09:00:00'));

        $watched = $this->makeVideo('ccccccccccc');
        $watched->markWatched(new DateTimeImmutable('2026-06-02 10:00:00'));

        $this->repository->save($plain);
        $this->repository->save($started);
        $this->repository->save($watched);
        $this->em->clear();

        $pool = $this->repository->findAllInSplitPool();

        self::assertCount(1, $pool);
        self::assertSame('aaaaaaaaaaa', $pool[0]->id()->value());
    }

    public function testSavePreservesTimestampsAfterMarkStarted(): void
    {
        $video = $this->makeVideo('zzzzzzzzzzz');
        $startedAt = new DateTimeImmutable('2026-06-03 14:30:00');
        $video->markStarted($startedAt);

        $this->repository->save($video);
        $this->em->clear();

        $loaded = $this->repository->findByYoutubeId(new YoutubeVideoId('zzzzzzzzzzz'));

        self::assertNotNull($loaded);
        self::assertEquals($startedAt, $loaded->startedAt());
        self::assertNull($loaded->watchedAt());
        self::assertFalse($loaded->isInSplitPool());
    }

    public function testFindAllReturnsAllRegardlessOfStatus(): void
    {
        $plain = $this->makeVideo('11111111111');
        $started = $this->makeVideo('22222222222');
        $started->markStarted(new DateTimeImmutable('2026-06-02 09:00:00'));
        $watched = $this->makeVideo('33333333333');
        $watched->markWatched(new DateTimeImmutable('2026-06-02 10:00:00'));

        $this->repository->save($plain);
        $this->repository->save($started);
        $this->repository->save($watched);
        $this->em->clear();

        $all = $this->repository->findAll();

        self::assertCount(3, $all);
    }
}
