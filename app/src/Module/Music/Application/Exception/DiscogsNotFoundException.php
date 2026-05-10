<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Exception;

/**
 * Discogs returned 404 — the requested user / collection / release does not exist.
 */
final class DiscogsNotFoundException extends DiscogsApiException
{
}
