<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Domain\ValueObject;

use InvalidArgumentException;

final readonly class YoutubeVideoId
{
    public function __construct(private string $value)
    {
        if ('' === $value) {
            throw new InvalidArgumentException('YouTube video ID must not be empty.');
        }

        if (strlen($value) > 20) {
            throw new InvalidArgumentException(sprintf('YouTube video ID must be at most 20 characters, %d given.', strlen($value)));
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
