<?php

declare(strict_types=1);

namespace App\Module\Music\Domain\ValueObject;

use InvalidArgumentException;

final readonly class AlbumTitle
{
    private const int MAX_LENGTH = 500;

    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ('' === $trimmed) {
            throw new InvalidArgumentException('Album title must not be empty.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Album title must not exceed %d characters.', self::MAX_LENGTH));
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
