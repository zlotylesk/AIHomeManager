<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\Command;

use DateTimeImmutable;

/**
 * Flag a video as started — it leaves the split pool but is not yet "watched".
 * Idempotency lives in the Video aggregate (T1): a re-dispatch keeps the
 * original timestamp.
 */
final readonly class MarkVideoStarted
{
    public function __construct(
        public string $youtubeVideoId,
        public DateTimeImmutable $at,
    ) {
    }
}
