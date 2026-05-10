<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Exception;

/**
 * Discogs returned 429 — we exceeded the API rate limit. Caller should back off
 * and retry (HMAI-38 RateLimitedHttpClient should normally prevent this, but
 * the upstream policy can still bite when other apps share our IP).
 */
final class DiscogsRateLimitException extends DiscogsApiException
{
}
