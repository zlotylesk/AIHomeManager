<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\QueryHandler;

use App\Module\Budget\Application\DTO\CategoryBudgetDTO;
use App\Module\Budget\Application\DTO\MonthlyBudgetReportDTO;
use App\Module\Budget\Application\MoneyColumn;
use App\Module\Budget\Application\MonthRange;
use App\Module\Budget\Application\Query\GetMonthlyBudgetReport;
use App\Module\Budget\Domain\Enum\TransactionType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The whole report — per-category breakdown and the overall totals derived
 * from it — comes from a single SUM/GROUP BY query (the ticket's explicit
 * ask): a LEFT JOIN from every category to this month's transactions means a
 * category with nothing spent still gets a row (COALESCE'd to zero) instead
 * of silently dropping out of the report. `amount` is a `budget_money`
 * packed "amount:currency" column (MoneyType), so it cannot be SUMmed
 * directly — implicit string-to-number coercion would work by accident on
 * MySQL but is exactly the kind of silently-fragile behaviour this project's
 * own 1.31.0 review theme warns against, so the numeric prefix is extracted
 * explicitly via SUBSTRING_INDEX + CAST instead.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetMonthlyBudgetReportHandler
{
    private const string SQL = <<<'SQL'
        SELECT
            c.id AS category_id,
            c.name AS category_name,
            c.type AS type,
            c.monthly_limit AS monthly_limit,
            COALESCE(SUM(CAST(SUBSTRING_INDEX(t.amount, ':', 1) AS SIGNED)), 0) AS spent_in_cents
        FROM budget_categories c
        LEFT JOIN budget_transactions t
            ON t.category_id = c.id AND t.date >= :monthStart AND t.date < :monthEnd
        GROUP BY c.id, c.name, c.type, c.monthly_limit
        ORDER BY c.name ASC
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(GetMonthlyBudgetReport $query): MonthlyBudgetReportDTO
    {
        $range = MonthRange::fromMonth($query->month);

        $rows = $this->connection->fetchAllAssociative(self::SQL, [
            'monthStart' => $range->startDate(),
            'monthEnd' => $range->endExclusiveDate(),
        ]);

        $categories = array_map($this->toCategoryDTO(...), $rows);

        $totalIncome = 0;
        $totalExpenses = 0;
        foreach ($categories as $category) {
            match ($category->type) {
                TransactionType::INCOME->value => $totalIncome += $category->spentInCents,
                TransactionType::EXPENSE->value => $totalExpenses += $category->spentInCents,
                default => null,
            };
        }

        return new MonthlyBudgetReportDTO(
            month: $query->month,
            totalIncomeInCents: $totalIncome,
            totalExpensesInCents: $totalExpenses,
            balanceInCents: $totalIncome - $totalExpenses,
            categories: $categories,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toCategoryDTO(array $row): CategoryBudgetDTO
    {
        $spentInCents = (int) $row['spent_in_cents'];

        $limitInCents = null;
        $limitCurrency = null;
        if (null !== $row['monthly_limit']) {
            [$limitInCents, $limitCurrency] = MoneyColumn::parse((string) $row['monthly_limit']);
        }

        $percentUsed = null;
        $overLimit = false;
        if (null !== $limitInCents && $limitInCents > 0) {
            $percentUsed = round($spentInCents / $limitInCents * 100, 2);
            $overLimit = $spentInCents > $limitInCents;
        }

        return new CategoryBudgetDTO(
            categoryId: (string) $row['category_id'],
            categoryName: (string) $row['category_name'],
            type: (string) $row['type'],
            spentInCents: $spentInCents,
            monthlyLimitInCents: $limitInCents,
            monthlyLimitCurrency: $limitCurrency,
            percentUsed: $percentUsed,
            overLimit: $overLimit,
        );
    }
}
