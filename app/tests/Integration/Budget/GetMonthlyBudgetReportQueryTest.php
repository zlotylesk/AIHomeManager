<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Messaging\QueryBus;
use App\Module\Budget\Application\DTO\CategoryBudgetDTO;
use App\Module\Budget\Application\Exception\MixedCurrencyException;
use App\Module\Budget\Application\Query\GetMonthlyBudgetReport;
use App\Module\Budget\Application\QueryHandler\GetMonthlyBudgetReportHandler;
use App\Module\Budget\Application\SystemCurrency;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetMonthlyBudgetReportQueryTest extends KernelTestCase
{
    private Connection $connection;
    private QueryBus $queryBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();
        $this->queryBus = $container->get(QueryBus::class);

        $this->connection->executeStatement('TRUNCATE TABLE budget_transactions');
        $this->connection->executeStatement('TRUNCATE TABLE budget_categories');
    }

    private function insertCategory(string $id, string $name, string $type, ?string $monthlyLimit = null): void
    {
        $this->connection->insert('budget_categories', [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'monthly_limit' => $monthlyLimit,
        ]);
    }

    private function insertTransaction(string $id, string $amount, string $date, string $categoryId, string $type): void
    {
        $this->connection->insert('budget_transactions', [
            'id' => $id,
            'amount' => $amount,
            'date' => $date,
            'category_id' => $categoryId,
            'type' => $type,
        ]);
    }

    /** @param CategoryBudgetDTO[] $categories */
    private function findCategory(array $categories, string $id): CategoryBudgetDTO
    {
        foreach ($categories as $category) {
            if ($id === $category->categoryId) {
                return $category;
            }
        }

        self::fail(sprintf('Category "%s" not found in report.', $id));
    }

    public function testComputesTotalsAndBalance(): void
    {
        $this->insertCategory('c-salary', 'Salary', 'income');
        $this->insertCategory('c-groceries', 'Groceries', 'expense');
        $this->insertTransaction('t-1', '500000:PLN', '2026-07-01', 'c-salary', 'income');
        $this->insertTransaction('t-2', '10000:PLN', '2026-07-05', 'c-groceries', 'expense');
        $this->insertTransaction('t-3', '5000:PLN', '2026-07-10', 'c-groceries', 'expense');

        $report = $this->queryBus->ask(new GetMonthlyBudgetReport('2026-07'));

        self::assertSame('2026-07', $report->month);
        self::assertSame(500000, $report->totalIncomeInCents);
        self::assertSame(15000, $report->totalExpensesInCents);
        self::assertSame(485000, $report->balanceInCents);
    }

    public function testExcludesTransactionsFromOtherMonths(): void
    {
        $this->insertCategory('c-groceries', 'Groceries', 'expense');
        $this->insertTransaction('t-1', '10000:PLN', '2026-06-30', 'c-groceries', 'expense');
        $this->insertTransaction('t-2', '5000:PLN', '2026-08-01', 'c-groceries', 'expense');

        $report = $this->queryBus->ask(new GetMonthlyBudgetReport('2026-07'));

        self::assertSame(0, $report->totalExpensesInCents);
    }

    public function testFlagsOverLimitCategory(): void
    {
        $this->insertCategory('c-groceries', 'Groceries', 'expense', '10000:PLN');
        $this->insertTransaction('t-1', '15000:PLN', '2026-07-05', 'c-groceries', 'expense');

        $report = $this->queryBus->ask(new GetMonthlyBudgetReport('2026-07'));

        $category = $this->findCategory($report->categories, 'c-groceries');
        self::assertSame(15000, $category->spentInCents);
        self::assertSame(10000, $category->monthlyLimitInCents);
        self::assertSame(150.0, $category->percentUsed);
        self::assertTrue($category->overLimit);
    }

    public function testCategoryUnderLimitIsNotFlagged(): void
    {
        $this->insertCategory('c-groceries', 'Groceries', 'expense', '10000:PLN');
        $this->insertTransaction('t-1', '4000:PLN', '2026-07-05', 'c-groceries', 'expense');

        $report = $this->queryBus->ask(new GetMonthlyBudgetReport('2026-07'));

        $category = $this->findCategory($report->categories, 'c-groceries');
        self::assertSame(40.0, $category->percentUsed);
        self::assertFalse($category->overLimit);
    }

    public function testCategoryAtExactLimitIsNotOverLimit(): void
    {
        $this->insertCategory('c-groceries', 'Groceries', 'expense', '10000:PLN');
        $this->insertTransaction('t-1', '10000:PLN', '2026-07-05', 'c-groceries', 'expense');

        $report = $this->queryBus->ask(new GetMonthlyBudgetReport('2026-07'));

        $category = $this->findCategory($report->categories, 'c-groceries');
        self::assertSame(100.0, $category->percentUsed);
        self::assertFalse($category->overLimit);
    }

    public function testCategoryWithoutLimitHasNullPercentAndIsNotOverLimit(): void
    {
        $this->insertCategory('c-groceries', 'Groceries', 'expense');
        $this->insertTransaction('t-1', '4000:PLN', '2026-07-05', 'c-groceries', 'expense');

        $report = $this->queryBus->ask(new GetMonthlyBudgetReport('2026-07'));

        $category = $this->findCategory($report->categories, 'c-groceries');
        self::assertNull($category->monthlyLimitInCents);
        self::assertNull($category->monthlyLimitCurrency);
        self::assertNull($category->percentUsed);
        self::assertFalse($category->overLimit);
    }

    public function testCategoryWithNoTransactionsThisMonthStillAppearsWithZeroSpent(): void
    {
        $this->insertCategory('c-untouched', 'Untouched', 'expense', '10000:PLN');

        $report = $this->queryBus->ask(new GetMonthlyBudgetReport('2026-07'));

        $category = $this->findCategory($report->categories, 'c-untouched');
        self::assertSame(0, $category->spentInCents);
        self::assertSame(0.0, $category->percentUsed);
        self::assertFalse($category->overLimit);
    }

    public function testMonthWithNoTransactionsReturnsZeroedTotals(): void
    {
        $this->insertCategory('c-groceries', 'Groceries', 'expense');
        $this->insertCategory('c-salary', 'Salary', 'income');

        $report = $this->queryBus->ask(new GetMonthlyBudgetReport('2026-07'));

        self::assertSame(0, $report->totalIncomeInCents);
        self::assertSame(0, $report->totalExpensesInCents);
        self::assertSame(0, $report->balanceInCents);
        self::assertCount(2, $report->categories);
    }

    public function testEveryFigureIsLabelledWithTheBudgetsCurrency(): void
    {
        $this->insertCategory('c-cur', 'Zakupy', 'expense', '50000:PLN');
        $this->insertTransaction('t-cur', '12000:PLN', '2026-07-04', 'c-cur', 'expense');

        $report = $this->queryBus->ask(new GetMonthlyBudgetReport('2026-07'));

        // A financial report is the last place that may state an amount without
        // its unit — before this the response was a bare 12000.
        self::assertSame('PLN', $report->currency);
    }

    public function testAMonthMixingCurrenciesIsRefusedRatherThanSummedIntoOneFigure(): void
    {
        // Seeded straight into the table, because this is exactly the state the
        // write side now refuses: 100 EUR and 100 PLN used to be reported as an
        // unlabelled 20000, which is a wrong answer wearing the shape of a
        // correct one. The API can no longer produce this, but data that
        // predates the rule — or arrives past it — must not be quietly totalled.
        $this->insertCategory('c-mixed', 'Podróże', 'expense');
        $this->insertTransaction('t-pln', '10000:PLN', '2026-07-04', 'c-mixed', 'expense');
        $this->insertTransaction('t-eur', '10000:EUR', '2026-07-05', 'c-mixed', 'expense');

        $this->expectException(MixedCurrencyException::class);
        $this->expectExceptionMessage('EUR');

        // Invoked directly — see testThrowsOnInvalidMonthFormat's note on why
        // the failure paths bypass queryBus->ask().
        $this->reportHandler()(new GetMonthlyBudgetReport('2026-07'));
    }

    public function testALimitInAnotherCurrencyIsRefusedEvenWhenEverySpendAgrees(): void
    {
        // The limit is compared against the sum, so a 500 EUR limit next to
        // 400 PLN of spending produced a percentage that meant nothing — the
        // exact symptom the ticket describes, from the other side.
        $this->insertCategory('c-limit', 'Rachunki', 'expense', '50000:EUR');
        $this->insertTransaction('t-ok', '40000:PLN', '2026-07-04', 'c-limit', 'expense');

        $this->expectException(MixedCurrencyException::class);

        $this->reportHandler()(new GetMonthlyBudgetReport('2026-07'));
    }

    public function testAMonthEntirelyInTheBudgetsCurrencyIsReportedNormally(): void
    {
        // The guard must not fire on ordinary data — a check that rejects
        // everything is indistinguishable from a broken report.
        $this->insertCategory('c-a', 'Zakupy', 'expense', '50000:PLN');
        $this->insertCategory('c-b', 'Wypłata', 'income');
        $this->insertTransaction('t-a', '12000:PLN', '2026-07-04', 'c-a', 'expense');
        $this->insertTransaction('t-b', '500000:PLN', '2026-07-01', 'c-b', 'income');

        $report = $this->queryBus->ask(new GetMonthlyBudgetReport('2026-07'));

        self::assertSame(500000, $report->totalIncomeInCents);
        self::assertSame(12000, $report->totalExpensesInCents);
        self::assertSame(488000, $report->balanceInCents);
    }

    public function testThrowsOnInvalidMonthFormat(): void
    {
        // Invoked directly — see GetTransactionsHandlerTest's identical note
        // on why this bypasses queryBus->ask().
        $this->expectException(InvalidArgumentException::class);
        $this->reportHandler()(new GetMonthlyBudgetReport('not-a-month'));
    }

    private function reportHandler(): GetMonthlyBudgetReportHandler
    {
        return new GetMonthlyBudgetReportHandler($this->connection, new SystemCurrency());
    }
}
