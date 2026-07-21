<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Application\Command;

/**
 * Fetch whatever the source now reports as listened to and record it.
 *
 * No payload: unlike the Last.fm poll, which carries a username, Spotify
 * identifies the listener by the stored OAuth token — and this is a single-user
 * system, so there is nothing to parameterize.
 */
final readonly class PollPodcastListens
{
}
