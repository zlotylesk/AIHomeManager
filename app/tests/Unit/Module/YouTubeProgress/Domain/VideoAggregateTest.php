<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\YouTubeProgress\Domain;

use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\ValueObject\ChannelName;
use App\Module\YouTubeProgress\Domain\ValueObject\VideoDuration;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class VideoAggregateTest extends TestCase
{
    private function newVideo(): Video
    {
        return Video::fromYouTube(
            new YoutubeVideoId('dQw4w9WgXcQ'),
            'Sample title',
            new ChannelName('Sample Channel'),
            new VideoDuration(213),
            new DateTimeImmutable('2026-06-01 10:00:00'),
        );
    }

    public function testFromYouTubeCreatesVideoInSplitPool(): void
    {
        $video = $this->newVideo();

        self::assertTrue($video->isInSplitPool());
        self::assertNull($video->startedAt());
        self::assertNull($video->watchedAt());
    }

    public function testMarkStartedSetsStartedAtAndRemovesFromSplitPool(): void
    {
        $video = $this->newVideo();
        $startedAt = new DateTimeImmutable('2026-06-02 09:30:00');

        $video->markStarted($startedAt);

        self::assertSame($startedAt, $video->startedAt());
        self::assertFalse($video->isInSplitPool());
        self::assertNull($video->watchedAt());
    }

    public function testMarkStartedIsIdempotent(): void
    {
        $video = $this->newVideo();
        $first = new DateTimeImmutable('2026-06-02 09:30:00');
        $second = new DateTimeImmutable('2026-06-02 10:30:00');

        $video->markStarted($first);
        $video->markStarted($second);

        self::assertSame($first, $video->startedAt(), 'The original startedAt must be preserved.');
    }

    public function testMarkStartedAfterMarkWatchedIsNoOp(): void
    {
        $video = $this->newVideo();
        $watchedAt = new DateTimeImmutable('2026-06-02 10:00:00');
        $video->markWatched($watchedAt);

        $video->markStarted(new DateTimeImmutable('2026-06-02 12:00:00'));

        self::assertNull($video->startedAt());
        self::assertSame($watchedAt, $video->watchedAt());
    }

    public function testMarkWatchedSetsWatchedAtAndRemovesFromSplitPool(): void
    {
        $video = $this->newVideo();
        $watchedAt = new DateTimeImmutable('2026-06-02 10:00:00');

        $video->markWatched($watchedAt);

        self::assertSame($watchedAt, $video->watchedAt());
        self::assertFalse($video->isInSplitPool());
    }

    public function testMarkWatchedIsIdempotent(): void
    {
        $video = $this->newVideo();
        $first = new DateTimeImmutable('2026-06-02 10:00:00');
        $second = new DateTimeImmutable('2026-06-02 11:00:00');

        $video->markWatched($first);
        $video->markWatched($second);

        self::assertSame($first, $video->watchedAt());
    }

    public function testMarkWatchedAfterMarkStartedKeepsStartedAtAndAddsWatchedAt(): void
    {
        $video = $this->newVideo();
        $startedAt = new DateTimeImmutable('2026-06-02 09:30:00');
        $watchedAt = new DateTimeImmutable('2026-06-02 10:00:00');

        $video->markStarted($startedAt);
        $video->markWatched($watchedAt);

        self::assertSame($startedAt, $video->startedAt());
        self::assertSame($watchedAt, $video->watchedAt());
        self::assertFalse($video->isInSplitPool());
    }

    public function testUpdateMetadataChangesTitleAndDuration(): void
    {
        $video = $this->newVideo();

        $video->updateMetadata('Refreshed title', new VideoDuration(360));

        self::assertSame('Refreshed title', $video->title());
        self::assertSame(360, $video->duration()->toSeconds());
    }
}
