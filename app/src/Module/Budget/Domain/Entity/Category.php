<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Entity;

use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\ValueObject\Money;
use InvalidArgumentException;

/**
 * A named bucket transactions are attributed to (e.g. "Zakupy spożywcze",
 * "Wynagrodzenie"), typed the same way as a Transaction (income/expense) so a
 * category cannot mix both, with an optional monthly spending limit.
 */
final readonly class Category
{
    private string $name;

    public function __construct(
        private string $id,
        string $name,
        private TransactionType $type,
        private ?Money $monthlyLimit = null,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Category id cannot be empty.');
        }

        $normalizedName = trim($name);
        if ('' === $normalizedName) {
            throw new InvalidArgumentException('Category name cannot be empty.');
        }

        $this->name = $normalizedName;
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
}
