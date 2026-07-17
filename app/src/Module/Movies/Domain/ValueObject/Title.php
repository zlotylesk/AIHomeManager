<?php

declare(strict_types=1);

namespace App\Module\Movies\Domain\ValueObject;

use InvalidArgumentException;

final readonly class Title
{
    public const int MAX_LENGTH = 255;

    private string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);

        if ('' === $normalized) {
            throw new InvalidArgumentException('Movie title cannot be empty.');
        }

        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Movie title cannot exceed %d characters.', self::MAX_LENGTH));
        }

        $this->value = $normalized;
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
