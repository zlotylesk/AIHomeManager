<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\Command;

/**
 * Rebuild every watch session from scratch off the current split pool.
 * No payload — the operation always works on the whole pool.
 */
final readonly class RegenerateSessions
{
}
