<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\Command;

use DateTimeImmutable;

/**
 * Flag a video as fully watched. Idempotency and the started→watched ordering
 * invariants live in the Video aggregate (T1): a re-dispatch keeps the original
 * watchedAt, and a prior startedAt is preserved.
 */
final readonly class MarkVideoWatched
{
    public function __construct(
        public string $youtubeVideoId,
        public DateTimeImmutable $at,
    ) {
    }
}
