<?php

declare(strict_types=1);

namespace App\Module\Books\Domain\ValueObject;

final class ISBN
{
    private readonly string $normalized;

    public function __construct(string $value)
    {
        $normalized = strtoupper(str_replace(['-', ' '], '', $value));

        if (strlen($normalized) === 10) {
            if (!$this->isValidIsbn10($normalized)) {
                throw new \InvalidArgumentException(sprintf('Invalid ISBN-10: "%s".', $value));
            }
        } elseif (strlen($normalized) === 13) {
            if (!$this->isValidIsbn13($normalized)) {
                throw new \InvalidArgumentException(sprintf('Invalid ISBN-13: "%s".', $value));
            }
        } else {
            throw new \InvalidArgumentException(sprintf('Invalid ISBN length for "%s": must be 10 or 13 characters.', $value));
        }

        $this->normalized = $normalized;
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
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $isbn[$i] * (10 - $i);
        }
        $sum += $isbn[9] === 'X' ? 10 : (int) $isbn[9];

        return $sum % 11 === 0;
    }

    private function isValidIsbn13(string $isbn): bool
    {
        if (!preg_match('/^\d{13}$/', $isbn)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int) $isbn[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return $sum % 10 === 0;
    }
}
