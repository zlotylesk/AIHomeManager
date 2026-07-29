<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\ValueObject\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Validated amount/date/type, built from the raw command inputs. One place
 * validates the transaction type enum and the YYYY-MM-DD date shape (the
 * amount validates itself through the Money VO), so AddTransaction and
 * UpdateTransaction do not duplicate the parsing.
 */
final readonly class TransactionInput
{
    private function __construct(
        public Money $amount,
        public DateTimeImmutable $date,
        public TransactionType $type,
    ) {
    }

    public static function fromRaw(int $amountInCents, string $currency, string $date, string $type): self
    {
        $resolvedType = TransactionType::tryFrom($type)
            ?? throw new InvalidArgumentException(sprintf('Unknown transaction type "%s".', $type));

        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $parsedDate) {
            throw new InvalidArgumentException(sprintf('Invalid transaction date "%s", expected YYYY-MM-DD.', $date));
        }

        return new self(new Money($amountInCents, $currency), $parsedDate, $resolvedType);
    }
}
