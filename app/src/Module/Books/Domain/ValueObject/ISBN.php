<?php

declare(strict_types=1);

namespace App\Module\Books\Domain\ValueObject;

use InvalidArgumentException;

final readonly class ISBN
{
    private string $normalized;

    public function __construct(string $value)
    {
        $normalizedValue = strtoupper(str_replace(['-', ' '], '', $value));

        if (10 === strlen($normalizedValue)) {
            if (!$this->isValidIsbn10($normalizedValue)) {
                throw new InvalidArgumentException(sprintf('Invalid ISBN-10: "%s".', $value));
            }
        } elseif (13 === strlen($normalizedValue)) {
            if (!$this->isValidIsbn13($normalizedValue)) {
                throw new InvalidArgumentException(sprintf('Invalid ISBN-13: "%s".', $value));
            }
        } else {
            throw new InvalidArgumentException(sprintf('Invalid ISBN length for "%s": must be 10 or 13 characters.', $value));
        }

        $this->normalized = $normalizedValue;
    }

    public function value(): string
    {
        return $this->normalized;
    }

    private function isValidIsbn10(string $isbn): bool
    {
        if (!preg_match('/^\d{9}[\dX]$/', $isbn)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; ++$i) {
            $sum += (int) $isbn[$i] * (10 - $i);
        }
        $sum += 'X' === $isbn[9] ? 10 : (int) $isbn[9];

        return 0 === $sum % 11;
    }

    private function isValidIsbn13(string $isbn): bool
    {
        if (!preg_match('/^\d{13}$/', $isbn)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 13; ++$i) {
            $sum += (int) $isbn[$i] * (0 === $i % 2 ? 1 : 3);
        }

        return 0 === $sum % 10;
    }
}
