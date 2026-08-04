<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\AddTransaction;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Application\Handler\AddTransactionHandler;
use App\Module\Budget\Application\SystemCurrency;
use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Entity\Transaction;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use App\Module\Budget\Domain\Repository\TransactionRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddTransactionHandlerTest extends TestCase
{
    public function testAddsTransactionAndReturnsId(): void
    {
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn(new Category('c-1', 'Groceries', TransactionType::EXPENSE));

        $transactions = $this->createMock(TransactionRepositoryInterface::class);
        $transactions->expects(self::once())->method('save')->with(self::callback(
            fn (Transaction $t): bool => 4999 === $t->amount()->amountInCents()
                && 'c-1' === $t->categoryId()
                && TransactionType::EXPENSE === $t->type()
        ));

        $handler = new AddTransactionHandler($transactions, $categories, new SystemCurrency());
        $id = $handler(new AddTransaction(4999, 'PLN', '2026-07-15', 'c-1', 'expense', 'Weekly shop'));

        self::assertNotEmpty($id);
    }

    public function testThrowsWhenCategoryNotFoundWithoutSaving(): void
    {
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn(null);

        $transactions = $this->createMock(TransactionRepositoryInterface::class);
        $transactions->expects(self::never())->method('save');

        $handler = new AddTransactionHandler($transactions, $categories, new SystemCurrency());

        $this->expectException(CategoryNotFoundException::class);
        $handler(new AddTransaction(1000, 'PLN', '2026-07-15', 'missing', 'expense'));
    }

    public function testThrowsOnInvalidTypeWithoutSaving(): void
    {
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn(new Category('c-1', 'Groceries', TransactionType::EXPENSE));

        $transactions = $this->createMock(TransactionRepositoryInterface::class);
        $transactions->expects(self::never())->method('save');

        $handler = new AddTransactionHandler($transactions, $categories, new SystemCurrency());

        $this->expectException(InvalidArgumentException::class);
        $handler(new AddTransaction(1000, 'PLN', '2026-07-15', 'c-1', 'not-a-type'));
    }

    public function testThrowsOnInvalidDateWithoutSaving(): void
    {
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn(new Category('c-1', 'Groceries', TransactionType::EXPENSE));

        $transactions = $this->createMock(TransactionRepositoryInterface::class);
        $transactions->expects(self::never())->method('save');

        $handler = new AddTransactionHandler($transactions, $categories, new SystemCurrency());

        $this->expectException(InvalidArgumentException::class);
        $handler(new AddTransaction(1000, 'PLN', 'not-a-date', 'c-1', 'expense'));
    }

    public function testThrowsOnZeroAmountWithoutSaving(): void
    {
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn(new Category('c-1', 'Groceries', TransactionType::EXPENSE));

        $transactions = $this->createMock(TransactionRepositoryInterface::class);
        $transactions->expects(self::never())->method('save');

        $handler = new AddTransactionHandler($transactions, $categories, new SystemCurrency());

        $this->expectException(InvalidArgumentException::class);
        $handler(new AddTransaction(0, 'PLN', '2026-07-15', 'c-1', 'expense'));
    }
}
