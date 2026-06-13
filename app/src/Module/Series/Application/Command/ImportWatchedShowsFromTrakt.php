<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Command;

/**
 * Triggers a one-directional Trakt → AIHM import of the user's watched shows
 * (HMAI-183). Single-user system, so the command carries no payload — the handler
 * reads the current watched truth from the connected Trakt account.
 *
 * Routed to the async transport (RabbitMQ); the work is rate-limited + I/O bound
 * and must never run inline in a request. Layer 5 (HMAI-184) dispatches it.
 */
final readonly class ImportWatchedShowsFromTrakt
{
}
