<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\Command;

/**
 * Triggers a one-directional Trakt → AIHM import of the user's watched movies
 * (HMAI-290). Single-user system, so the command carries no payload — the handler
 * reads the current watched truth from the connected Trakt account.
 *
 * Routed to the async transport (RabbitMQ); the work is rate-limited + I/O bound
 * and must never run inline in a request.
 */
final readonly class ImportWatchedMoviesFromTrakt
{
}
