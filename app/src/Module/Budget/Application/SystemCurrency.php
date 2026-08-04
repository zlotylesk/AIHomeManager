<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use App\Module\Budget\Domain\ValueObject\Money;
use InvalidArgumentException;
use RuntimeException;

/**
 * The one currency this budget is kept in.
 *
 * {@see Money} validates any ISO 4217 code, because a currency code is a
 * property of an amount and the Domain has no business reading configuration.
 * What money the *ledger* is kept in is a different question, and this is where
 * it is answered — in Application, alongside the other cross-aggregate rules
 * ({@see TransactionCategoryMatch}), so both writes that carry a Money (a
 * transaction and a category's monthly limit) are held to one rule in one
 * place.
 *
 * Full multi-currency support was considered and deliberately rejected: it is
 * not a currency column but exchange rates, their history, the rate date a
 * month-old transaction converts at, a currency on the category and its limit,
 * and a decision about what the month's balance is even denominated in. None of
 * that exists, the frontend only ever sends one currency, and half of such a
 * mechanism is again an amount that can be quietly untrue.
 *
 * A foreign amount is **rejected, never rewritten to the configured currency**.
 * Overwriting would accept bad input and silently change its meaning, which is
 * the failure this rule exists to remove — the same call made for a transaction
 * whose type disagreed with its category.
 */
final readonly class SystemCurrency
{
    private string $code;

    public function __construct(string $code = Money::DEFAULT_CURRENCY)
    {
        $normalized = strtoupper(trim($code));

        // Misconfiguration fails at boot rather than at the first transaction:
        // an unusable value here would otherwise reject every write in the
        // module with a message about the caller's currency.
        if (1 !== preg_match('/^[A-Z]{3}$/', $normalized)) {
            throw new RuntimeException(sprintf('BUDGET_CURRENCY must be a 3-letter ISO 4217 code, got "%s".', $code));
        }

        $this->code = $normalized;
    }

    public function code(): string
    {
        return $this->code;
    }

    /**
     * @throws InvalidArgumentException when the amount is in another currency,
     *                                  which the API surfaces as 422
     */
    public function assertSupported(Money $money): void
    {
        if (!$this->matches($money->currency())) {
            throw new InvalidArgumentException(sprintf('This budget is kept in %s; an amount in %s cannot be recorded. Multi-currency budgeting is not supported.', $this->code, $money->currency()));
        }
    }

    public function matches(string $currency): bool
    {
        return strtoupper(trim($currency)) === $this->code;
    }
}
