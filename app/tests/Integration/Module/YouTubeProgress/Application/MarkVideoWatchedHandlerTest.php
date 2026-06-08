<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\YouTubeProgress\Application;

use App\Module\YouTubeProgress\Application\Command\MarkVideoWatched;
use App\Module\YouTubeProgress\Application\Handler\MarkVideoWatchedHandler;
use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\ValueObject\ChannelName;
use App\Module\YouTubeProgress\Domain\ValueObject\VideoDuration;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use App\Module\YouTubeProgress\Infrastructure\Persistence\DoctrineVideoRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MarkVideoWatchedHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DoctrineVideoRepository $videos;
    private MarkVideoWatchedHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->videos = new DoctrineVideoRepository($this->em);
        $this->handler = new MarkVideoWatchedHandler($this->videos);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE videos');
    }

    private function makeVideo(string $id): Video
    {
        return Video::fromYouTube(
            new YoutubeVideoId($id),
            'Title '.$id,
            new ChannelName('Channel A'),
            new VideoDuration(600),
            new DateTimeImmutable('2026-06-01 10:00:00'),
        );
    }

    public function testMarkWatchedSetsTimestamp(): void
    {
        $this->videos->save($this->makeVideo('dQw4w9WgXcQ'));
        $at = new DateTimeImmutable('2026-06-08 20:00:00');

        ($this->handler)(new MarkVideoWatched('dQw4w9WgXcQ', $at));
        $this->em->clear();

        $loaded = $this->videos->findByYoutubeId(new YoutubeVideoId('dQw4w9WgXcQ'));
        self::assertNotNull($loaded);
        self::assertEquals($at, $loaded->watchedAt());
    }

    public function testMarkWatchedTwiceIsIdempotent(): void
    {
        $this->videos->save($this->makeVideo('dQw4w9WgXcQ'));
        $first = new DateTimeImmutable('2026-06-08 20:00:00');
        $second = new DateTimeImmutable('2026-06-09 11:00:00');

        ($this->handler)(new MarkVideoWatched('dQw4w9WgXcQ', $first));
        $this->em->clear();
        ($this->handler)(new MarkVideoWatched('dQw4w9WgXcQ', $second));
        $this->em->clear();

        $loaded = $this->videos->findByYoutubeId(new YoutubeVideoId('dQw4w9WgXcQ'));
        self::assertNotNull($loaded);
        self::assertEquals($first, $loaded->watchedAt());
    }

    public function testMarkWatchedAfterMarkStartedKeepsBothTimestamps(): void
    {
        $startedAt = new DateTimeImmutable('2026-06-08 18:00:00');
        $video = $this->makeVideo('dQw4w9WgXcQ');
        $video->markStarted($startedAt);
        $this->videos->save($video);
        $this->em->clear();

        $watchedAt = new DateTimeImmutable('2026-06-08 20:00:00');
        ($this->handler)(new MarkVideoWatched('dQw4w9WgXcQ', $watchedAt));
        $this->em->clear();

        $loaded = $this->videos->findByYoutubeId(new YoutubeVideoId('dQw4w9WgXcQ'));
        self::assertNotNull($loaded);
        self::assertEquals($startedAt, $loaded->startedAt());
        self::assertEquals($watchedAt, $loaded->watchedAt());
    }

    public function testMarkWatchedThrows404WhenVideoNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        ($this->handler)(new MarkVideoWatched('missing0001', new DateTimeImmutable('2026-06-08 20:00:00')));
    }
}
