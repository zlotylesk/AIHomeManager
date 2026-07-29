<?php

declare(strict_types=1);

namespace App\Module\Budget\Application;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The half-open `[start, endExclusive)` day range a `YYYY-MM` string denotes,
 * and the one place that string is validated.
 *
 * Both rules here exist because `DateTimeImmutable::createFromFormat()` is
 * lenient: it *rolls over* an out-of-range month instead of rejecting it, so
 * `"2026-13"` silently parses to January 2027 and `"2026-00"` to December
 * 2025. Left unchecked that turns a typo into a report for a completely
 * different month, labelled with the month the caller asked for — a wrong
 * answer presented as a correct one, which in a ledger is worse than an
 * error. The round-trip comparison below is what makes the parse strict: a
 * value that does not format back to exactly what was supplied was never that
 * month (this also rejects the unpadded `"2026-7"`, which is not the
 * `YYYY-MM` shape the API documents).
 *
 * It is a shared class rather than a private helper because three readers
 * need the same range — the transaction list, the monthly report and the CSV
 * export — and each one had grown its own copy of the parse. Duplicated
 * leniency is how a fourth reader would quietly reintroduce the bug (the
 * MoneyColumn precedent).
 */
final readonly class MonthRange
{
    private function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $endExclusive,
    ) {
    }

    public static function fromMonth(string $month): self
    {
        $start = DateTimeImmutable::createFromFormat('!Y-m', $month);

        if (false === $start || $start->format('Y-m') !== $month) {
            throw new InvalidArgumentException(sprintf('Invalid month "%s", expected YYYY-MM.', $month));
        }

        return new self($start, $start->modify('+1 month'));
    }

    public function startDate(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function endExclusiveDate(): string
    {
        return $this->endExclusive->format('Y-m-d');
    }
}
