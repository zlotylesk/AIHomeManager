<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Application\Command;

use App\Module\Podcasts\Domain\ReadModel\ListenedEpisode;

/**
 * Record one observed listen. The single entry point for both the scheduled poll
 * (HMAI-325) and any manual log.
 *
 * It carries the Domain read model whole rather than restating its ten fields as
 * command properties: this command exists precisely to persist what the
 * listening-history port hands back, and a parallel field list would be a copy
 * to keep in sync for no gain. Application depending on Domain is the allowed
 * direction, and the command is dispatched on the synchronous command.bus, so
 * nothing here has to survive serialization.
 */
final readonly class LogPodcastListeningSession
{
    public function __construct(
        public ListenedEpisode $listened,
    ) {
    }
}
