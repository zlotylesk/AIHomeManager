<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Handler;

use App\Module\Music\Application\Command\LogListeningSession;
use App\Module\Music\Application\Command\PollLastFmRecentTracks;
use App\Module\Music\Domain\Enum\ListeningSource;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Strategy A (HMAI-144): the scheduler polls Last.fm `user.getRecentTracks`
 * every 30 min and turns each play into a LogListeningSession command. The
 * downstream handler dedups, so re-polling the same window is harmless.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class PollLastFmRecentTracksHandler
{
    public function __construct(
        private MusicListeningHistoryInterface $listeningHistory,
        // No #[Target]: command.bus is the default bus, so it has no named target.
        private MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(PollLastFmRecentTracks $command): void
    {
        $tracks = $this->listeningHistory->getRecentTracks($command->username, $command->limit);

        foreach ($tracks as $track) {
            $this->commandBus->dispatch(new LogListeningSession(
                artist: $track->artist,
                title: $track->album,
                playedAt: $track->playedAt,
                source: ListeningSource::LASTFM_SCROBBLE,
            ));
        }
    }
}
