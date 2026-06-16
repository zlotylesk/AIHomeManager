<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\YouTubeProgress\Application\Service;

use App\Module\YouTubeProgress\Application\Service\WatchSessionSplitter;
use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\Entity\WatchSession;
use App\Module\YouTubeProgress\Domain\ValueObject\ChannelName;
use App\Module\YouTubeProgress\Domain\ValueObject\VideoDuration;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WatchSessionSplitterTest extends TestCase
{
    private WatchSessionSplitter $splitter;
    private DateTimeImmutable $fixedNow;
    private int $idCounter = 0;

    protected function setUp(): void
    {
        $this->splitter = new WatchSessionSplitter();
        $this->fixedNow = new DateTimeImmutable('2026-06-07 12:00:00');
    }

    private function makeVideo(string $channel, int $durationSeconds, ?DateTimeImmutable $startedAt = null, ?DateTimeImmutable $watchedAt = null): Video
    {
        $id = str_pad((string) ++$this->idCounter, 11, 'x', STR_PAD_LEFT);

        $video = Video::fromYouTube(
            new YoutubeVideoId($id),
            'Title '.$id,
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

        return $video;
    }

    /** @return string[] */
    private function videoIdValues(WatchSession $session): array
    {
        return array_map(static fn (YoutubeVideoId $id): string => $id->value(), $session->videoIds());
    }

    public function testEmptyInputProducesNoSessions(): void
    {
        self::assertSame([], $this->splitter->split([], WatchSessionSplitter::DEFAULT_TARGET_SECONDS, $this->fixedNow));
    }

    public function testAllVideosMarkedWatchedProducesNoSessions(): void
    {
        $videos = [
            $this->makeVideo('A', 600, watchedAt: new DateTimeImmutable('2026-06-06 10:00:00')),
            $this->makeVideo('B', 800, watchedAt: new DateTimeImmutable('2026-06-06 11:00:00')),
        ];

        self::assertSame([], $this->splitter->split($videos, WatchSessionSplitter::DEFAULT_TARGET_SECONDS, $this->fixedNow));
    }

    public function testStartedVideosAreSkipped(): void
    {
        $started = $this->makeVideo('A', 600, startedAt: new DateTimeImmutable('2026-06-06 10:00:00'));
        $available = $this->makeVideo('A', 700);

        $sessions = $this->splitter->split([$started, $available], WatchSessionSplitter::DEFAULT_TARGET_SECONDS, $this->fixedNow);

        self::assertCount(1, $sessions);
        self::assertSame([$available->id()->value()], $this->videoIdValues($sessions[0]));
    }

    public function testSingleVideoUnderTargetProducesOneSession(): void
    {
        $video = $this->makeVideo('Solo', 600);

        $sessions = $this->splitter->split([$video], WatchSessionSplitter::DEFAULT_TARGET_SECONDS, $this->fixedNow);

        self::assertCount(1, $sessions);
        self::assertSame(600, $sessions[0]->totalDurationSeconds());
        self::assertSame($this->fixedNow, $sessions[0]->createdAt());
    }

    public function testTwoChannelsLargerFirstWinsOrder(): void
    {
        $a1 = $this->makeVideo('A', 300);
        $a2 = $this->makeVideo('A', 400);
        $a3 = $this->makeVideo('A', 500);
        $b1 = $this->makeVideo('B', 200);

        $sessions = $this->splitter->split([$b1, $a1, $a2, $a3], WatchSessionSplitter::DEFAULT_TARGET_SECONDS, $this->fixedNow);

        self::assertCount(1, $sessions);
        $values = $this->videoIdValues($sessions[0]);

        self::assertSame(
            [$a1->id()->value(), $a2->id()->value(), $a3->id()->value(), $b1->id()->value()],
            $values,
        );
    }

    public function testWithinChannelShortestFirst(): void
    {
        $long = $this->makeVideo('A', 800);
        $short = $this->makeVideo('A', 300);
        $mid = $this->makeVideo('A', 600);

        $sessions = $this->splitter->split([$long, $short, $mid], WatchSessionSplitter::DEFAULT_TARGET_SECONDS, $this->fixedNow);

        self::assertCount(1, $sessions);
        self::assertSame(
            [$short->id()->value(), $mid->id()->value(), $long->id()->value()],
            $this->videoIdValues($sessions[0]),
        );
        self::assertSame(1700, $sessions[0]->totalDurationSeconds());
    }

    public function testOverflowVideoCreatesOwnSession(): void
    {
        $oversize = $this->makeVideo('A', 2400);

        $sessions = $this->splitter->split([$oversize], WatchSessionSplitter::DEFAULT_TARGET_SECONDS, $this->fixedNow);

        self::assertCount(1, $sessions);
        self::assertSame([$oversize->id()->value()], $this->videoIdValues($sessions[0]));
        self::assertSame(2400, $sessions[0]->totalDurationSeconds());
    }

    public function testOverflowVideoClosesPreviousSession(): void
    {
        $small = $this->makeVideo('A', 500);
        $mid = $this->makeVideo('A', 600);
        $oversize = $this->makeVideo('A', 2400);

        $sessions = $this->splitter->split([$small, $mid, $oversize], WatchSessionSplitter::DEFAULT_TARGET_SECONDS, $this->fixedNow);

        self::assertCount(2, $sessions);

        self::assertSame([$small->id()->value(), $mid->id()->value()], $this->videoIdValues($sessions[0]));
        self::assertSame(1100, $sessions[0]->totalDurationSeconds());
        self::assertSame([$oversize->id()->value()], $this->videoIdValues($sessions[1]));
        self::assertSame(2400, $sessions[1]->totalDurationSeconds());
    }

    public function testFilledSessionTriggersNewSession(): void
    {
        $a = $this->makeVideo('A', 800);
        $b = $this->makeVideo('A', 800);
        $c = $this->makeVideo('A', 800);

        $sessions = $this->splitter->split([$a, $b, $c], WatchSessionSplitter::DEFAULT_TARGET_SECONDS, $this->fixedNow);

        self::assertCount(2, $sessions);
        self::assertSame(1600, $sessions[0]->totalDurationSeconds());
        self::assertSame(800, $sessions[1]->totalDurationSeconds());
    }

    public function testRealisticMixedScenarioMatchesDocumentedAlgorithm(): void
    {
        $a300 = $this->makeVideo('A', 300);
        $a400 = $this->makeVideo('A', 400);
        $a600 = $this->makeVideo('A', 600);
        $a700 = $this->makeVideo('A', 700);
        $a1900 = $this->makeVideo('A', 1900);
        $b1200 = $this->makeVideo('B', 1200);
        $b800 = $this->makeVideo('B', 800);
        $c500 = $this->makeVideo('C', 500);

        $sessions = $this->splitter->split(
            [$c500, $a1900, $b800, $a300, $b1200, $a700, $a400, $a600],
            WatchSessionSplitter::DEFAULT_TARGET_SECONDS,
            $this->fixedNow,
        );

        self::assertCount(5, $sessions);

        self::assertSame([$a300->id()->value(), $a400->id()->value(), $a600->id()->value()], $this->videoIdValues($sessions[0]));
        self::assertSame(1300, $sessions[0]->totalDurationSeconds());

        self::assertSame([$a700->id()->value()], $this->videoIdValues($sessions[1]));
        self::assertSame(700, $sessions[1]->totalDurationSeconds());

        self::assertSame([$a1900->id()->value()], $this->videoIdValues($sessions[2]));
        self::assertSame(1900, $sessions[2]->totalDurationSeconds());

        self::assertSame([$b800->id()->value()], $this->videoIdValues($sessions[3]));
        self::assertSame(800, $sessions[3]->totalDurationSeconds());

        self::assertSame([$b1200->id()->value(), $c500->id()->value()], $this->videoIdValues($sessions[4]));
        self::assertSame(1700, $sessions[4]->totalDurationSeconds());
    }

    public function testTargetParameterOverride(): void
    {
        $a = $this->makeVideo('A', 400);
        $b = $this->makeVideo('A', 400);

        $sessions = $this->splitter->split([$a, $b], 600, $this->fixedNow);

        self::assertCount(2, $sessions);
        self::assertSame(400, $sessions[0]->totalDurationSeconds());
        self::assertSame(400, $sessions[1]->totalDurationSeconds());
    }

    public function testAllVideosFromOneChannelBelowTarget(): void
    {
        $a = $this->makeVideo('A', 200);
        $b = $this->makeVideo('A', 300);
        $c = $this->makeVideo('A', 400);

        $sessions = $this->splitter->split([$a, $b, $c], WatchSessionSplitter::DEFAULT_TARGET_SECONDS, $this->fixedNow);

        self::assertCount(1, $sessions);
        self::assertSame(900, $sessions[0]->totalDurationSeconds());
        self::assertSame(
            [$a->id()->value(), $b->id()->value(), $c->id()->value()],
            $this->videoIdValues($sessions[0]),
        );
    }
}
