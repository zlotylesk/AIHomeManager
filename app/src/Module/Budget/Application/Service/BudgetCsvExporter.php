<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Service;

use App\Module\Budget\Application\DTO\MonthlyBudgetReportDTO;
use App\Module\Budget\Application\MoneyColumn;
use App\Module\Budget\Application\MonthRange;
use App\Module\Budget\Domain\Enum\TransactionType;
use Doctrine\DBAL\Connection;
use Generator;
use InvalidArgumentException;

/**
 * Row sources for the Budget export (the Tasks/Articles CSV exporter pattern,
 * HMAI-36).
 *
 * The two halves are deliberately asymmetric:
 *
 * - **Transactions** are read here, straight from DBAL via a cursor. The
 *   ledger grows without bound over years of use, so the rows are streamed
 *   rather than materialised (matching TaskCsvExporter/ArticleCsvExporter),
 *   and the read joins `budget_categories` because a CSV full of category
 *   UUIDs is useless to the human opening it — something the `TransactionDTO`
 *   the query side returns cannot provide.
 * - **The report** is NOT re-queried here. It carries real derivation (the
 *   LEFT JOIN that keeps a category with no activity in the report, the
 *   percent/over-limit rules), so a second copy of that SQL would eventually
 *   let the exported report disagree with the one on screen. The controller
 *   dispatches `GetMonthlyBudgetReport` on query.bus and hands the DTO here,
 *   which only maps it to rows. Its size is bounded by the category count, so
 *   nothing is lost by not streaming.
 *
 * Amounts are rendered as decimal units, not the stored minor units: an export
 * is read by a person (or summed in a spreadsheet), and a column of `499900`
 * next to a `PLN` column would be read as ~500 000 zł rather than 4 999 zł.
 */
final readonly class BudgetCsvExporter
{
    /** @var list<string> */
    public const array TRANSACTION_HEADERS = ['date', 'category', 'type', 'amount', 'currency', 'description'];

    /** @var list<string> */
    public const array REPORT_HEADERS = ['category', 'type', 'spent', 'monthlyLimit', 'limitCurrency', 'percentUsed', 'overLimit'];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Streams ledger rows one at a time via a DBAL cursor, newest first (the
     * same order the list endpoint uses).
     *
     * @return Generator<int, list<scalar|null>>
     */
    public function transactionRows(?string $month = null, ?string $categoryId = null, ?string $type = null): Generator
    {
        $conditions = [];
        $params = [];

        if (null !== $month) {
            $range = MonthRange::fromMonth($month);

            $conditions[] = 't.date >= :monthStart AND t.date < :monthEnd';
            $params['monthStart'] = $range->startDate();
            $params['monthEnd'] = $range->endExclusiveDate();
        }

        if (null !== $categoryId) {
            $conditions[] = 't.category_id = :categoryId';
            $params['categoryId'] = $categoryId;
        }

        if (null !== $type) {
            if (null === TransactionType::tryFrom($type)) {
                throw new InvalidArgumentException(sprintf('Unknown transaction type "%s".', $type));
            }

            $conditions[] = 't.type = :type';
            $params['type'] = $type;
        }

        $sql = 'SELECT t.date, t.amount, t.type, t.description, c.name AS category_name
                FROM budget_transactions t
                LEFT JOIN budget_categories c ON c.id = t.category_id';

        if ([] !== $conditions) {
            $sql .= ' WHERE '.implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY t.date DESC';

        $result = $this->connection->executeQuery($sql, $params);
        while (false !== ($row = $result->fetchAssociative())) {
            [$amountInCents, $currency] = MoneyColumn::parse((string) $row['amount']);

            yield [
                (string) $row['date'],
                null === $row['category_name'] ? '' : (string) $row['category_name'],
                (string) $row['type'],
                self::decimal($amountInCents),
                $currency,
                null === $row['description'] ? '' : (string) $row['description'],
            ];
        }
    }

    /**
     * Maps an already-computed report onto rows — one per category, including
     * the ones nothing was spent against. The overall totals deliberately do
     * NOT become a trailing row here: a totals line inside a per-category
     * table breaks whatever the user pivots or sums in a spreadsheet. The PDF,
     * which is read rather than processed, renders them as a separate block.
     *
     * @return Generator<int, list<scalar|null>>
     */
    public function reportRows(MonthlyBudgetReportDTO $report): Generator
    {
        foreach ($report->categories as $category) {
            yield [
                $category->categoryName,
                $category->type,
                self::decimal($category->spentInCents),
                null === $category->monthlyLimitInCents ? '' : self::decimal($category->monthlyLimitInCents),
                $category->monthlyLimitCurrency ?? '',
                null === $category->percentUsed ? '' : number_format($category->percentUsed, 2, '.', ''),
                $category->overLimit ? '1' : '0',
            ];
        }
    }

    /**
     * The report's overall figures, formatted the same way as the rows. They
     * are deliberately not a trailing CSV row (see reportRows) — only the PDF
     * renders them, and it gets them from here so a total and a category
     * amount in the same document cannot be formatted two different ways.
     *
     * @return array{income: string, expenses: string, balance: string}
     */
    public function reportTotals(MonthlyBudgetReportDTO $report): array
    {
        return [
            'income' => self::decimal($report->totalIncomeInCents),
            'expenses' => self::decimal($report->totalExpensesInCents),
            'balance' => self::decimal($report->balanceInCents),
        ];
    }

    private static function decimal(int $amountInCents): string
    {
        return number_format($amountInCents / 100, 2, '.', '');
    }
}
