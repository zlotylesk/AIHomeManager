<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Entity;

use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\ValueObject\Money;
use InvalidArgumentException;

/**
 * A named bucket transactions are attributed to (e.g. "Zakupy spożywcze",
 * "Wynagrodzenie"), typed the same way as a Transaction (income/expense) so a
 * category cannot mix both, with an optional monthly spending limit. The
 * type is immutable once created (the Goal precedent) — only the name and
 * the limit can change.
 */
final class Category
{
    private string $name;

    public function __construct(
        private readonly string $id,
        string $name,
        private readonly TransactionType $type,
        private ?Money $monthlyLimit = null,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Category id cannot be empty.');
        }

        $this->name = self::normalizeName($name);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): TransactionType
    {
        return $this->type;
    }

    public function monthlyLimit(): ?Money
    {
        return $this->monthlyLimit;
    }

    public function rename(string $name): void
    {
        $this->name = self::normalizeName($name);
    }

    /**
     * Set the monthly spending limit, or clear it when given null.
     */
    public function setMonthlyLimit(?Money $monthlyLimit): void
    {
        $this->monthlyLimit = $monthlyLimit;
    }

    private static function normalizeName(string $name): string
    {
        $normalized = trim($name);
        if ('' === $normalized) {
            throw new InvalidArgumentException('Category name cannot be empty.');
        }

        return $normalized;
    }
}
