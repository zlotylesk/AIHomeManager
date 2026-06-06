<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Domain\ValueObject;

use InvalidArgumentException;

final readonly class VideoDuration
{
    public function __construct(private int $seconds)
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException(sprintf('Video duration must be non-negative, %d given.', $seconds));
        }
    }

    public static function fromIsoDuration(string $iso): self
    {
        // YouTube Data API returns ISO 8601 durations (RFC 3339) like PT1H2M3S.
        // Hours, minutes and seconds are each optional, but the PT prefix is not.
        // Anything outside this shape is a malformed payload — fail loudly.
        if (1 !== preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $matches, PREG_UNMATCHED_AS_NULL)) {
            throw new InvalidArgumentException(sprintf('Malformed ISO 8601 duration: "%s".', $iso));
        }

        $hours = (int) ($matches[1] ?? 0);
        $minutes = (int) ($matches[2] ?? 0);
        $seconds = (int) ($matches[3] ?? 0);

        if (0 === $hours && 0 === $minutes && 0 === $seconds && 'PT0S' !== $iso) {
            // Guards the regex edge case where bare "PT" matches with all three
            // groups null — semantically that's not a valid duration.
            throw new InvalidArgumentException(sprintf('Malformed ISO 8601 duration: "%s".', $iso));
        }

        return new self($hours * 3600 + $minutes * 60 + $seconds);
    }

    public function toSeconds(): int
    {
        return $this->seconds;
    }

    public function equals(self $other): bool
    {
        return $this->seconds === $other->seconds;
    }
}
