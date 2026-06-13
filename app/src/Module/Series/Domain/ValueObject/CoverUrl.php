<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\ValueObject;

use InvalidArgumentException;

/**
 * A series poster/cover image URL (HMAI-190). Mirrors the Books module's
 * CoverUrl: only http/https are allowed and the value must be a well-formed
 * URL. Cross-module reuse is forbidden by the hexagonal boundaries (deptrac),
 * so each module keeps its own copy.
 */
final readonly class CoverUrl
{
    private const array ALLOWED_SCHEMES = ['http', 'https'];

    private string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if ('' === $trimmed) {
            throw new InvalidArgumentException('Cover URL cannot be empty.');
        }

        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));

        if ('' === $scheme) {
            throw new InvalidArgumentException(sprintf('Invalid cover URL: "%s".', $value));
        }

        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new InvalidArgumentException(sprintf('Cover URL scheme must be http or https, got "%s".', $scheme));
        }

        if (false === filter_var($trimmed, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(sprintf('Invalid cover URL: "%s".', $value));
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
