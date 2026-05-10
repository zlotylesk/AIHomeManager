<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Exception;

/**
 * Discogs returned 401 or 403 — OAuth1 credentials are missing, revoked, or otherwise
 * invalid. Caller should send the user through /auth/discogs to re-authorize.
 */
final class DiscogsAuthException extends DiscogsApiException
{
}
