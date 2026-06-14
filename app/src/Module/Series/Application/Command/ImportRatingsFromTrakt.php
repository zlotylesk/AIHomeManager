<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Command;

/**
 * Imports the user's Trakt ratings onto the Series aggregate (HMAI-220).
 *
 * No payload — single-user. Routed async and chained after the watched-shows
 * import, so the rated shows/seasons/episodes already exist to attach to.
 */
final readonly class ImportRatingsFromTrakt
{
}
