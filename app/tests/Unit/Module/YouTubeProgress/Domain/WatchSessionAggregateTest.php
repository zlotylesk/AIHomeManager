<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\YouTubeProgress\Domain;

use App\Module\YouTubeProgress\Domain\Entity\WatchSession;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WatchSessionAggregateTest extends TestCase
{
    private function createdAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-06 10:00:00');
    }

    public function testCreateRequiresNonEmptyVideoIds(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WatchSession::create([], 0, $this->createdAt());
    }

    public function testCreateGeneratesUniqueId(): void
    {
        $a = WatchSession::create([new YoutubeVideoId('aaaaaaaaaaa')], 0, $this->createdAt());
        $b = WatchSession::create([new YoutubeVideoId('bbbbbbbbbbb')], 0, $this->createdAt());

        self::assertNotSame($a->id()->value, $b->id()->value);
    }

    public function testCreatePreservesVideoOrder(): void
    {
        $videoIds = [
            new YoutubeVideoId('aaaaaaaaaaa'),
            new YoutubeVideoId('bbbbbbbbbbb'),
            new YoutubeVideoId('ccccccccccc'),
        ];

        $session = WatchSession::create($videoIds, 600, $this->createdAt());

        $resultValues = array_map(static fn (YoutubeVideoId $id): string => $id->value(), $session->videoIds());

        self::assertSame(['aaaaaaaaaaa', 'bbbbbbbbbbb', 'ccccccccccc'], $resultValues);
    }

    public function testCreateRejectsNegativeDuration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WatchSession::create([new YoutubeVideoId('aaaaaaaaaaa')], -1, $this->createdAt());
    }

    public function testCreatePreservesTotalDuration(): void
    {
        $session = WatchSession::create([new YoutubeVideoId('aaaaaaaaaaa')], 1500, $this->createdAt());

        self::assertSame(1500, $session->totalDurationSeconds());
    }

    public function testCreatePreservesCreatedAt(): void
    {
        $when = new DateTimeImmutable('2026-06-06 12:34:56');

        $session = WatchSession::create([new YoutubeVideoId('aaaaaaaaaaa')], 0, $when);

        self::assertSame($when, $session->createdAt());
    }

    public function testCreatedSessionIsNotPushedToYouTube(): void
    {
        $session = WatchSession::create([new YoutubeVideoId('aaaaaaaaaaa')], 0, $this->createdAt());

        self::assertFalse($session->isPushedToYouTube());
        self::assertNull($session->youtubePlaylistId());
    }

    public function testMarkPushedToYouTubeSetsPlaylistId(): void
    {
        $session = WatchSession::create([new YoutubeVideoId('aaaaaaaaaaa')], 0, $this->createdAt());

        $session->markPushedToYouTube('PLxxx');

        self::assertSame('PLxxx', $session->youtubePlaylistId());
        self::assertTrue($session->isPushedToYouTube());
    }

    public function testMarkPushedToYouTubeIsIdempotent(): void
    {
        $session = WatchSession::create([new YoutubeVideoId('aaaaaaaaaaa')], 0, $this->createdAt());

        $session->markPushedToYouTube('PLfirst');
        $session->markPushedToYouTube('PLsecond');

        self::assertSame('PLfirst', $session->youtubePlaylistId(), 'The original playlist ID must be preserved.');
    }
}
