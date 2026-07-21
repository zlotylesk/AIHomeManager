<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Application;

use App\Module\Podcasts\Application\Command\LogPodcastListeningSession;
use App\Module\Podcasts\Application\Handler\LogPodcastListeningSessionHandler;
use App\Module\Podcasts\Domain\ReadModel\ListenedEpisode;
use App\Module\Podcasts\Domain\ValueObject\ListeningProgress;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LogPodcastListeningSessionHandler::class)]
final class LogPodcastListeningSessionHandlerTest extends TestCase
{
    private InMemoryPodcastRepository $podcasts;
    private InMemoryEpisodeRepository $episodes;
    private InMemoryPodcastListeningSessionRepository $sessions;
    private LogPodcastListeningSessionHandler $handler;

    protected function setUp(): void
    {
        $this->podcasts = new InMemoryPodcastRepository();
        $this->episodes = new InMemoryEpisodeRepository();
        $this->sessions = new InMemoryPodcastListeningSessionRepository();
        $this->handler = new LogPodcastListeningSessionHandler(
            $this->podcasts,
            $this->episodes,
            $this->sessions,
        );
    }

    public function testRecordsAListenAndMaterializesTheCatalogItRefersTo(): void
    {
        ($this->handler)(new LogPodcastListeningSession($this->listened()));

        self::assertCount(1, $this->sessions->saved);
        self::assertCount(1, $this->podcasts->saved);
        self::assertCount(1, $this->episodes->saved);

        $podcast = $this->podcasts->only();
        self::assertSame('show-1', $podcast->externalId());
        self::assertSame('Radio Nowak', $podcast->title()->value());
        self::assertSame('Studio Nowak', $podcast->publisher());
        self::assertSame('https://i.scdn.co/image/show-1.jpg', $podcast->coverUrl());

        $episode = $this->episodes->only();
        self::assertSame('ep-1', $episode->externalId());
        self::assertSame($podcast->id(), $episode->podcastId());
        self::assertSame(1_800_000, $episode->durationMs());

        $session = $this->sessions->only();
        self::assertSame($podcast->id(), $session->podcastId());
        self::assertSame($episode->id(), $session->episodeId());
        self::assertSame(900_000, $session->progress()->resumePositionMs());
    }

    /**
     * The whole reason the command exists — the poll re-reports every started
     * episode on every run, so an unchanged re-observation must be a no-op.
     */
    public function testRePollingTheSameDayCreatesNoDuplicate(): void
    {
        $command = new LogPodcastListeningSession($this->listened());

        ($this->handler)($command);
        ($this->handler)($command);
        ($this->handler)($command);

        self::assertCount(1, $this->sessions->saved);
        self::assertSame(1, $this->sessions->writes, 'An unchanged re-poll must not write at all.');
        self::assertCount(1, $this->podcasts->saved, 'The show is recognized, not minted again.');
        self::assertCount(1, $this->episodes->saved);
    }

    /**
     * The poll re-reports every started episode on every run, so an unchanged
     * catalog must not be rewritten either — otherwise one routine poll flushes
     * twice per listened episode for nothing.
     */
    public function testAnUnchangedCatalogIsNotRewrittenOnEveryPoll(): void
    {
        $command = new LogPodcastListeningSession($this->listened());

        ($this->handler)($command);
        ($this->handler)($command);
        ($this->handler)($command);

        self::assertSame(1, $this->podcasts->writes, 'Only the first sight of the show earns a write.');
        self::assertSame(1, $this->episodes->writes);
    }

    public function testAdvancedProgressUpdatesTheDaysSessionInPlace(): void
    {
        ($this->handler)(new LogPodcastListeningSession($this->listened(
            listenedAt: new DateTimeImmutable('2026-07-21 08:00:00'),
            progress: new ListeningProgress(300_000, false),
        )));

        ($this->handler)(new LogPodcastListeningSession($this->listened(
            listenedAt: new DateTimeImmutable('2026-07-21 20:00:00'),
            progress: ListeningProgress::completed(1_750_000),
        )));

        self::assertCount(1, $this->sessions->saved, 'Still one listen, not two.');
        self::assertSame(2, $this->sessions->writes);

        $session = $this->sessions->only();
        self::assertTrue($session->progress()->fullyPlayed());
        self::assertSame(1_750_000, $session->progress()->resumePositionMs());
    }

    public function testListeningAgainTheNextDayIsRecordedSeparately(): void
    {
        ($this->handler)(new LogPodcastListeningSession($this->listened(
            listenedAt: new DateTimeImmutable('2026-07-21 20:00:00'),
        )));

        ($this->handler)(new LogPodcastListeningSession($this->listened(
            listenedAt: new DateTimeImmutable('2026-07-22 08:00:00'),
        )));

        self::assertCount(2, $this->sessions->saved);
        self::assertCount(1, $this->episodes->saved, 'The same episode, listened to on two days.');
    }

    public function testASecondEpisodeOfAKnownShowReusesThatShow(): void
    {
        ($this->handler)(new LogPodcastListeningSession($this->listened()));
        ($this->handler)(new LogPodcastListeningSession($this->listened(
            episodeExternalId: 'ep-2',
            episodeTitle: 'The next one',
        )));

        self::assertCount(1, $this->podcasts->saved);
        self::assertCount(2, $this->episodes->saved);

        $podcastId = $this->podcasts->only()->id();
        foreach ($this->episodes->saved as $episode) {
            self::assertSame($podcastId, $episode->podcastId());
        }
    }

    /**
     * Artwork is cosmetic: a URL the shared CoverUrl VO rejects costs the image,
     * never the listening record.
     */
    public function testAnUnusableCoverIsDroppedRatherThanLosingTheListen(): void
    {
        ($this->handler)(new LogPodcastListeningSession($this->listened(coverUrl: 'javascript:alert(1)')));

        self::assertCount(1, $this->sessions->saved);
        self::assertNull($this->podcasts->only()->coverUrl());
    }

    /**
     * The catalog mirrors the source, so a title or publisher the source changed
     * must follow — but the show keeps its identity rather than being re-minted.
     */
    public function testRefreshesTheShowMetadataFromTheLatestObservation(): void
    {
        ($this->handler)(new LogPodcastListeningSession($this->listened()));
        $originalId = $this->podcasts->only()->id();

        ($this->handler)(new LogPodcastListeningSession($this->listened(
            listenedAt: new DateTimeImmutable('2026-07-22 08:00:00'),
            publisher: 'Nowak Media',
        )));

        self::assertCount(1, $this->podcasts->saved);
        self::assertSame($originalId, $this->podcasts->only()->id());
        self::assertSame('Nowak Media', $this->podcasts->only()->publisher());
        self::assertSame(2, $this->podcasts->writes, 'A real change does earn a write.');
    }

    private function listened(
        ?DateTimeImmutable $listenedAt = null,
        ?ListeningProgress $progress = null,
        string $episodeExternalId = 'ep-1',
        string $episodeTitle = 'Halfway through',
        ?string $coverUrl = 'https://i.scdn.co/image/show-1.jpg',
        ?string $publisher = 'Studio Nowak',
    ): ListenedEpisode {
        return new ListenedEpisode(
            'show-1',
            'Radio Nowak',
            $episodeExternalId,
            $episodeTitle,
            $listenedAt ?? new DateTimeImmutable('2026-07-21 08:00:00'),
            $progress ?? new ListeningProgress(900_000, false),
            $publisher,
            $coverUrl,
            new DateTimeImmutable('2026-07-01'),
            1_800_000,
        );
    }
}
