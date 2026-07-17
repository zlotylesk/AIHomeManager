<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\Command;

/**
 * Triggers a one-directional Trakt → AIHM import of the user's movie ratings
 * (HMAI-290), chained after the watched-movies import. No payload (single-user).
 *
 * Routed to the async transport (RabbitMQ); rate-limited + I/O bound, never inline.
 */
final readonly class ImportMovieRatingsFromTrakt
{
}
