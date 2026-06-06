<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Domain\ValueObject;

use InvalidArgumentException;

final readonly class ChannelName
{
    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ('' === $trimmed) {
            throw new InvalidArgumentException('Channel name must not be empty.');
        }

        if (strlen($trimmed) > 255) {
            throw new InvalidArgumentException(sprintf('Channel name must be at most 255 characters, %d given.', strlen($trimmed)));
        }

        $this->value = $trimmed;
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
