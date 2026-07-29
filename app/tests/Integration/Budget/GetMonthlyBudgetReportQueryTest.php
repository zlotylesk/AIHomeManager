<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Messaging\QueryBus;
use App\Module\Budget\Application\DTO\CategoryBudgetDTO;
use App\Module\Budget\Application\Query\GetMonthlyBudgetReport;
use App\Module\Budget\Application\QueryHandler\GetMonthlyBudgetReportHandler;
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

    public function testThrowsOnInvalidMonthFormat(): void
    {
        // Invoked directly — see GetTransactionsHandlerTest's identical note
        // on why this bypasses queryBus->ask().
        $handler = new GetMonthlyBudgetReportHandler($this->connection);

        $this->expectException(InvalidArgumentException::class);
        $handler(new GetMonthlyBudgetReport('not-a-month'));
    }
}
