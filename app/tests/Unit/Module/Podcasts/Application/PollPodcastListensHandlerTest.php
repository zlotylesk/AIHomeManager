<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Application;

use App\Module\Podcasts\Application\Command\LogPodcastListeningSession;
use App\Module\Podcasts\Application\Command\PollPodcastListens;
use App\Module\Podcasts\Application\Handler\PollPodcastListensHandler;
use App\Module\Podcasts\Domain\Port\PodcastListeningHistoryInterface;
use App\Module\Podcasts\Domain\ReadModel\ListenedEpisode;
use App\Module\Podcasts\Domain\ValueObject\ListeningProgress;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(PollPodcastListensHandler::class)]
final class PollPodcastListensHandlerTest extends TestCase
{
    public function testDispatchesOneLogCommandPerListenedEpisode(): void
    {
        $bus = new RecordingBus();

        $this->handlerFor([$this->listened('ep-1'), $this->listened('ep-2')], $bus)(new PollPodcastListens());

        self::assertCount(2, $bus->dispatched);
        self::assertSame('ep-1', $bus->dispatched[0]->listened->episodeExternalId);
        self::assertSame('ep-2', $bus->dispatched[1]->listened->episodeExternalId);
    }

    public function testAnEmptySourceDispatchesNothing(): void
    {
        $bus = new RecordingBus();

        $this->handlerFor([], $bus)(new PollPodcastListens());

        self::assertSame([], $bus->dispatched);
    }

    /**
     * A user who never connected Spotify would otherwise put one "not
     * connected" failure in the DLQ every half hour, forever. The job is
     * idempotent and fires again in 30 minutes, so retrying buys nothing.
     */
    public function testAnUnreadableSourceIsLoggedRatherThanFailingTheJob(): void
    {
        $bus = new RecordingBus();

        $history = self::createStub(PodcastListeningHistoryInterface::class);
        $history->method('fetchListenedEpisodes')
            ->willThrowException(new RuntimeException('Spotify account not connected.'));

        $handler = new PollPodcastListensHandler($history, $bus, new NullLogger());

        $handler(new PollPodcastListens());

        self::assertSame([], $bus->dispatched);
    }

    /**
     * One unusable episode must not discard every listen behind it.
     */
    public function testOneFailingEpisodeDoesNotAbortTheSweep(): void
    {
        $bus = new RecordingBus(failOn: 'ep-bad');

        $this->handlerFor(
            [$this->listened('ep-1'), $this->listened('ep-bad'), $this->listened('ep-3')],
            $bus,
        )(new PollPodcastListens());

        $recorded = array_map(
            static fn (LogPodcastListeningSession $c): string => $c->listened->episodeExternalId,
            $bus->dispatched,
        );

        self::assertSame(['ep-1', 'ep-3'], $recorded);
    }

    /**
     * @param list<ListenedEpisode> $listened
     */
    private function handlerFor(array $listened, MessageBusInterface $bus): PollPodcastListensHandler
    {
        return new PollPodcastListensHandler($this->historyReturning($listened), $bus, new NullLogger());
    }

    /**
     * @param list<ListenedEpisode> $listened
     *
     * @return PodcastListeningHistoryInterface&Stub
     */
    private function historyReturning(array $listened): PodcastListeningHistoryInterface
    {
        $history = self::createStub(PodcastListeningHistoryInterface::class);
        $history->method('fetchListenedEpisodes')->willReturn($listened);

        return $history;
    }

    private function listened(string $episodeExternalId): ListenedEpisode
    {
        return new ListenedEpisode(
            'show-1',
            'Radio Nowak',
            $episodeExternalId,
            'Odcinek',
            new DateTimeImmutable('2026-07-21 08:00:00'),
            new ListeningProgress(900_000, false),
        );
    }
}
