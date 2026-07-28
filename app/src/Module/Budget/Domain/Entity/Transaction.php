<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Entity;

use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\ValueObject\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A single ledger entry: an amount of money moved on a given date, attributed
 * to one category, with an optional free-text note.
 */
final readonly class Transaction
{
    public function __construct(
        private string $id,
        private Money $amount,
        private DateTimeImmutable $date,
        private string $categoryId,
        private TransactionType $type,
        private ?string $description = null,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Transaction id cannot be empty.');
        }

        if ('' === trim($categoryId)) {
            throw new InvalidArgumentException('Transaction category id cannot be empty.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function date(): DateTimeImmutable
    {
        return $this->date;
    }

    public function categoryId(): string
    {
        return $this->categoryId;
    }

    public function type(): TransactionType
    {
        return $this->type;
    }

    public function description(): ?string
    {
        return $this->description;
    }
}
