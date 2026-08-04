<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Messaging\QueryBus;
use App\Module\Budget\Application\DTO\TransactionDTO;
use App\Module\Budget\Application\Query\GetTransactions;
use App\Module\Budget\Application\QueryHandler\GetTransactionsHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetTransactionsQueryTest extends KernelTestCase
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
    }

    private function insertTransaction(string $id, string $amount, string $date, string $categoryId, string $type, ?string $description = null): void
    {
        $this->connection->insert('budget_transactions', [
            'id' => $id,
            'amount' => $amount,
            'date' => $date,
            'category_id' => $categoryId,
            'type' => $type,
            'description' => $description,
        ]);
    }

    public function testReturnsAllTransactionsSortedByDateDescending(): void
    {
        $this->insertTransaction('t-1', '1000:PLN', '2026-07-01', 'c-1', 'expense');
        $this->insertTransaction('t-2', '2000:PLN', '2026-07-15', 'c-1', 'expense');
        $this->insertTransaction('t-3', '3000:PLN', '2026-07-10', 'c-1', 'expense');

        $result = $this->queryBus->ask(new GetTransactions())->items;

        self::assertCount(3, $result);
        self::assertSame(['t-2', 't-3', 't-1'], array_map(static fn (TransactionDTO $t): string => $t->id, $result));
    }

    public function testMapsAmountCurrencyAndDescription(): void
    {
        $this->insertTransaction('t-1', '4999:PLN', '2026-07-15', 'c-groceries', 'expense', 'Weekly shop');

        $result = $this->queryBus->ask(new GetTransactions())->items;

        self::assertCount(1, $result);
        $dto = $result[0];
        self::assertSame(4999, $dto->amountInCents);
        self::assertSame('PLN', $dto->currency);
        self::assertSame('2026-07-15', $dto->date);
        self::assertSame('c-groceries', $dto->categoryId);
        self::assertSame('expense', $dto->type);
        self::assertSame('Weekly shop', $dto->description);
    }

    public function testDescriptionIsNullWhenAbsent(): void
    {
        $this->insertTransaction('t-1', '1000:PLN', '2026-07-15', 'c-1', 'expense');

        $result = $this->queryBus->ask(new GetTransactions())->items;

        self::assertNull($result[0]->description);
    }

    public function testFiltersByMonth(): void
    {
        $this->insertTransaction('t-1', '1000:PLN', '2026-06-30', 'c-1', 'expense');
        $this->insertTransaction('t-2', '2000:PLN', '2026-07-01', 'c-1', 'expense');
        $this->insertTransaction('t-3', '3000:PLN', '2026-07-31', 'c-1', 'expense');
        $this->insertTransaction('t-4', '4000:PLN', '2026-08-01', 'c-1', 'expense');

        $result = $this->queryBus->ask(new GetTransactions(month: '2026-07'))->items;

        self::assertCount(2, $result);
        self::assertSame(['t-3', 't-2'], array_map(static fn (TransactionDTO $t): string => $t->id, $result));
    }

    public function testFiltersByCategory(): void
    {
        $this->insertTransaction('t-1', '1000:PLN', '2026-07-01', 'c-groceries', 'expense');
        $this->insertTransaction('t-2', '2000:PLN', '2026-07-02', 'c-salary', 'income');

        $result = $this->queryBus->ask(new GetTransactions(categoryId: 'c-salary'))->items;

        self::assertCount(1, $result);
        self::assertSame('t-2', $result[0]->id);
    }

    public function testFiltersByType(): void
    {
        $this->insertTransaction('t-1', '1000:PLN', '2026-07-01', 'c-groceries', 'expense');
        $this->insertTransaction('t-2', '2000:PLN', '2026-07-02', 'c-salary', 'income');

        $result = $this->queryBus->ask(new GetTransactions(type: 'income'))->items;

        self::assertCount(1, $result);
        self::assertSame('t-2', $result[0]->id);
    }

    public function testCombinesFilters(): void
    {
        $this->insertTransaction('t-1', '1000:PLN', '2026-07-01', 'c-groceries', 'expense');
        $this->insertTransaction('t-2', '2000:PLN', '2026-07-02', 'c-groceries', 'income');
        $this->insertTransaction('t-3', '3000:PLN', '2026-08-01', 'c-groceries', 'expense');

        $result = $this->queryBus->ask(new GetTransactions(month: '2026-07', categoryId: 'c-groceries', type: 'expense'))->items;

        self::assertCount(1, $result);
        self::assertSame('t-1', $result[0]->id);
    }

    public function testReturnsEmptyArrayWhenNoMatches(): void
    {
        self::assertSame([], $this->queryBus->ask(new GetTransactions(categoryId: 'does-not-exist'))->items);
    }

    public function testThrowsOnInvalidMonthFormat(): void
    {
        // Invoked directly (not via queryBus->ask()) — Messenger wraps a
        // handler exception in HandlerFailedException, which would make this
        // assertion test the bus's wrapping behaviour rather than the
        // handler's own validation.
        $handler = new GetTransactionsHandler($this->connection);

        $this->expectException(InvalidArgumentException::class);
        $handler(new GetTransactions(month: 'not-a-month'));
    }

    public function testThrowsOnUnknownType(): void
    {
        $handler = new GetTransactionsHandler($this->connection);

        $this->expectException(InvalidArgumentException::class);
        $handler(new GetTransactions(type: 'bogus'));
    }
}
