<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The title of a podcast show or of one of its episodes. One VO serves both
 * aggregates (the Movies\Title precedent) — the rules are identical and two
 * twin classes would only duplicate the invariant.
 *
 * The length cap follows AlbumTitle rather than the 255 used elsewhere: podcast
 * and episode titles from a catalog are routinely long ("Episode 412 — …").
 */
final readonly class Title
{
    private const int MAX_LENGTH = 500;

    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ('' === $trimmed) {
            throw new InvalidArgumentException('Title must not be empty.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Title must not exceed %d characters.', self::MAX_LENGTH));
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
