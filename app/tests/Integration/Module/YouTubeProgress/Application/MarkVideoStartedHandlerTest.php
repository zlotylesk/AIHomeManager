<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\YouTubeProgress\Application;

use App\Module\YouTubeProgress\Application\Command\MarkVideoStarted;
use App\Module\YouTubeProgress\Application\Handler\MarkVideoStartedHandler;
use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\ValueObject\ChannelName;
use App\Module\YouTubeProgress\Domain\ValueObject\VideoDuration;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use App\Module\YouTubeProgress\Infrastructure\Persistence\DoctrineVideoRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MarkVideoStartedHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DoctrineVideoRepository $videos;
    private MarkVideoStartedHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->videos = new DoctrineVideoRepository($this->em);
        $this->handler = new MarkVideoStartedHandler($this->videos);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE videos');
    }

    private function saveVideoInPool(string $id): void
    {
        $this->videos->save(Video::fromYouTube(
            new YoutubeVideoId($id),
            'Title '.$id,
            new ChannelName('Channel A'),
            new VideoDuration(600),
            new DateTimeImmutable('2026-06-01 10:00:00'),
        ));
    }

    public function testMarkStartedSetsTimestamp(): void
    {
        $this->saveVideoInPool('dQw4w9WgXcQ');
        $at = new DateTimeImmutable('2026-06-08 18:00:00');

        ($this->handler)(new MarkVideoStarted('dQw4w9WgXcQ', $at));
        $this->em->clear();

        $loaded = $this->videos->findByYoutubeId(new YoutubeVideoId('dQw4w9WgXcQ'));
        self::assertNotNull($loaded);
        self::assertEquals($at, $loaded->startedAt());
        self::assertNull($loaded->watchedAt());
    }

    public function testMarkStartedTwiceIsIdempotent(): void
    {
        $this->saveVideoInPool('dQw4w9WgXcQ');
        $first = new DateTimeImmutable('2026-06-08 18:00:00');
        $second = new DateTimeImmutable('2026-06-09 09:30:00');

        ($this->handler)(new MarkVideoStarted('dQw4w9WgXcQ', $first));
        $this->em->clear();
        ($this->handler)(new MarkVideoStarted('dQw4w9WgXcQ', $second));
        $this->em->clear();

        $loaded = $this->videos->findByYoutubeId(new YoutubeVideoId('dQw4w9WgXcQ'));
        self::assertNotNull($loaded);
        self::assertEquals($first, $loaded->startedAt());
    }

    public function testMarkStartedThrows404WhenVideoNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        ($this->handler)(new MarkVideoStarted('missing0001', new DateTimeImmutable('2026-06-08 18:00:00')));
    }
}
