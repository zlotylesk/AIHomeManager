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

        // createFromFormat is lenient and ROLLS OVER an impossible date rather
        // than rejecting it: "2026-02-31" parses happily to 2026-03-03. Left
        // unchecked, a mistyped day silently books money into the following
        // month — the transaction is accepted with a 201, February's report is
        // short by that amount and March's is long by it, with nothing
        // anywhere reporting a problem. The round-trip comparison is what makes
        // the parse strict: a date that does not format back to exactly what
        // was supplied is not the date the caller meant.
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException(sprintf('Invalid transaction date "%s", expected YYYY-MM-DD.', $date));
        }

        return new self(new Money($amountInCents, $currency), $parsedDate, $resolvedType);
    }
}
