<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Application\Handler;

use App\Module\Podcasts\Application\Command\LogPodcastListeningSession;
use App\Module\Podcasts\Application\Command\PollPodcastListens;
use App\Module\Podcasts\Domain\Port\PodcastListeningHistoryInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The scheduled sweep that fills the listening history without the user doing
 * anything — the Music PollLastFmRecentTracks pattern.
 *
 * Overlapping windows are harmless by construction: the source reports state
 * rather than events, so every poll re-reports every started episode, and the
 * downstream LogPodcastListeningSession collapses them onto one record per
 * episode per day (HMAI-324).
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class PollPodcastListensHandler
{
    public function __construct(
        private PodcastListeningHistoryInterface $listeningHistory,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PollPodcastListens $command): void
    {
        try {
            $listened = $this->listeningHistory->fetchListenedEpisodes();
        } catch (RuntimeException $e) {
            // Logged and dropped rather than rethrown, deliberately. A user who
            // has not connected Spotify would otherwise fill the DLQ with one
            // "not connected" failure every half hour, forever; and a real
            // outage gains nothing from Messenger's retry either, because this
            // job is idempotent and fires again in 30 minutes regardless.
            $this->logger->warning('Podcast listening poll could not read the source', [
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($listened as $episode) {
            // One unusable episode must not cost the rest of the sweep — a
            // title longer than the Title VO allows would otherwise discard
            // every listen behind it (the Notifications sweep precedent).
            try {
                $this->commandBus->dispatch(new LogPodcastListeningSession($episode));
            } catch (RuntimeException $e) {
                $this->logger->warning('Could not record a podcast listen, skipping it', [
                    'episode_external_id' => $episode->episodeExternalId,
                    'reason' => $e->getMessage(),
                ]);
            }
        }
    }
}
