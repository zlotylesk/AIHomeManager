<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Exception;

/**
 * Discogs returned 5xx, a network error happened, or the response was malformed.
 * Treat as a transient outage — retry later, but do not loop tightly.
 */
final class DiscogsUnavailableException extends DiscogsApiException
{
}
