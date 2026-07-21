<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Domain;

use App\Module\Podcasts\Domain\Entity\PodcastListeningSession;
use App\Module\Podcasts\Domain\ValueObject\ListeningProgress;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PodcastListeningSession::class)]
final class PodcastListeningSessionTest extends TestCase
{
    /**
     * The whole point of the day-resolution key. The source reports no listen
     * moment, so each poll stamps a fresh observation time; a second-resolution
     * hash (the Music rule) would make every poll a new occurrence and dedup
     * would never fire once.
     */
    public function testTwoObservationsOnTheSameDayShareOneIdentity(): void
    {
        $morning = $this->session(new DateTimeImmutable('2026-07-21 08:15:00'));
        $evening = $this->session(new DateTimeImmutable('2026-07-21 21:47:33'));

        self::assertSame($morning->dedupHash(), $evening->dedupHash());
    }

    public function testListeningAgainTheNextDayIsANewOccurrence(): void
    {
        $today = $this->session(new DateTimeImmutable('2026-07-21 21:00:00'));
        $tomorrow = $this->session(new DateTimeImmutable('2026-07-22 07:00:00'));

        self::assertNotSame($today->dedupHash(), $tomorrow->dedupHash());
    }

    /**
     * The day is bucketed in UTC, so the same instant expressed in two zones
     * must not split into two occurrences.
     */
    public function testTheSameInstantInAnotherTimezoneIsTheSameOccurrence(): void
    {
        $warsaw = $this->session(new DateTimeImmutable('2026-07-21 09:00:00', new DateTimeZone('Europe/Warsaw')));
        $utc = $this->session(new DateTimeImmutable('2026-07-21 07:00:00', new DateTimeZone('UTC')));

        self::assertSame($warsaw->dedupHash(), $utc->dedupHash());
    }

    public function testADifferentEpisodeIsADifferentOccurrence(): void
    {
        $first = $this->session(episodeId: 'episode-1');
        $second = $this->session(episodeId: 'episode-2');

        self::assertNotSame($first->dedupHash(), $second->dedupHash());
    }

    public function testAbsorbsAnAdvancedPosition(): void
    {
        $session = $this->session(progress: new ListeningProgress(300_000, false));

        self::assertTrue($session->observeProgress(new ListeningProgress(900_000, false)));
        self::assertSame(900_000, $session->progress()->resumePositionMs());
    }

    public function testReportsNoChangeWhenTheObservationRepeatsWhatIsStored(): void
    {
        $session = $this->session(progress: new ListeningProgress(900_000, false));

        self::assertFalse(
            $session->observeProgress(new ListeningProgress(900_000, false)),
            'An unchanged observation must not provoke a write.'
        );
    }

    /**
     * Restarting a finished episode drops the resume position back to near zero.
     * Taking that at face value would rewrite the day to look like the listener
     * had barely started.
     */
    public function testIgnoresARewind(): void
    {
        $session = $this->session(progress: new ListeningProgress(1_500_000, false));

        self::assertFalse($session->observeProgress(new ListeningProgress(2_000, false)));
        self::assertSame(1_500_000, $session->progress()->resumePositionMs());
    }

    public function testFinishingAnEpisodeCountsEvenWhenThePositionResets(): void
    {
        $session = $this->session(progress: new ListeningProgress(1_500_000, false));

        self::assertTrue($session->observeProgress(ListeningProgress::completed()));
        self::assertTrue($session->progress()->fullyPlayed());
        self::assertSame(1_500_000, $session->progress()->resumePositionMs(), 'The furthest point reached is kept.');
    }

    public function testOnceFullyPlayedStaysFullyPlayed(): void
    {
        $session = $this->session(progress: ListeningProgress::completed(1_500_000));

        self::assertFalse($session->observeProgress(new ListeningProgress(60_000, false)));
        self::assertTrue($session->progress()->fullyPlayed());
    }

    public function testRejectsASessionWithNoEpisode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must belong to an episode');

        $this->session(episodeId: '  ');
    }

    public function testRejectsASessionWithNoPodcast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must belong to a podcast');

        new PodcastListeningSession(
            'session-1',
            '',
            'episode-1',
            new DateTimeImmutable(),
            ListeningProgress::notStarted(),
            new DateTimeImmutable(),
        );
    }

    private function session(
        ?DateTimeImmutable $listenedAt = null,
        string $episodeId = 'episode-1',
        ?ListeningProgress $progress = null,
    ): PodcastListeningSession {
        return new PodcastListeningSession(
            'session-1',
            'podcast-1',
            $episodeId,
            $listenedAt ?? new DateTimeImmutable('2026-07-21 08:00:00'),
            $progress ?? new ListeningProgress(60_000, false),
            new DateTimeImmutable(),
        );
    }
}
