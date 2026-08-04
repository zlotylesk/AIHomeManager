<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Messaging\QueryBus;
use App\Module\Budget\Application\DTO\CategoryDTO;
use App\Module\Budget\Application\Query\GetCategories;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetCategoriesQueryTest extends KernelTestCase
{
    private Connection $connection;
    private QueryBus $queryBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();
        $this->queryBus = $container->get(QueryBus::class);

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

    public function testReturnsAllCategoriesSortedByName(): void
    {
        $this->insertCategory('c-1', 'Transport', 'expense');
        $this->insertCategory('c-2', 'Groceries', 'expense');

        $result = $this->queryBus->ask(new GetCategories())->items;

        self::assertCount(2, $result);
        self::assertSame(['Groceries', 'Transport'], array_map(static fn (CategoryDTO $c): string => $c->name, $result));
    }

    public function testMapsMonthlyLimitWhenSet(): void
    {
        $this->insertCategory('c-1', 'Groceries', 'expense', '50000:PLN');

        $result = $this->queryBus->ask(new GetCategories())->items;

        self::assertSame(50000, $result[0]->monthlyLimitAmountInCents);
        self::assertSame('PLN', $result[0]->monthlyLimitCurrency);
    }

    public function testMonthlyLimitIsNullWhenUnset(): void
    {
        $this->insertCategory('c-1', 'Salary', 'income');

        $result = $this->queryBus->ask(new GetCategories())->items;

        self::assertNull($result[0]->monthlyLimitAmountInCents);
        self::assertNull($result[0]->monthlyLimitCurrency);
    }

    public function testReturnsEmptyArrayWhenNoCategories(): void
    {
        self::assertSame([], $this->queryBus->ask(new GetCategories())->items);
    }
}
