<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Enum\TransactionType;
use InvalidArgumentException;

/**
 * Enforces the one invariant the module had documented in three places and
 * checked in none: a transaction's income/expense type must match the type of
 * the category it is filed under.
 *
 * `Category`'s own docblock states it ("typed the same way as a Transaction …
 * so a category cannot mix both") and its `type` is deliberately immutable for
 * exactly that reason, but nothing rejected the mismatch — and the two halves
 * of the module then disagreed about the same money. The transaction list and
 * the CSV export filter on the *transaction's* `type`, while the monthly
 * report attributes a category's whole SUM to the *category's* `type`, since
 * grouping by category is the only way to produce the spend-vs-limit
 * breakdown in one query. So an income transaction booked under an expense
 * category was listed as income, counted as an expense, and left the month's
 * balance wrong by twice its amount — with a 201 Created and no signal
 * anywhere. Neither handler was wrong on its own; the gap was between them.
 *
 * The check lives here, not on the `Transaction` aggregate, because it spans
 * two aggregates and resolving a `Category` needs a repository, which a Domain
 * entity must not depend on (the same reason the category-existence check sits
 * in the handlers). It is deliberately a rejection rather than a silent
 * correction: overriding the caller's `type` to match the category would
 * accept bad input and quietly change its meaning — the very failure mode
 * this fix exists to remove.
 *
 * With the invariant enforced, the report's `c.type` grouping is correct by
 * construction: every transaction in a category now carries that category's
 * type.
 */
final readonly class TransactionCategoryMatch
{
    private function __construct()
    {
    }

    public static function assertTypesAgree(TransactionType $type, Category $category): void
    {
        if ($type === $category->type()) {
            return;
        }

        throw new InvalidArgumentException(sprintf('Transaction type "%s" does not match category "%s", which is "%s". A category cannot mix income and expense.', $type->value, $category->name(), $category->type()->value));
    }
}
