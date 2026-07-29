<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\UpdateTransaction;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Application\Exception\TransactionNotFoundException;
use App\Module\Budget\Application\Handler\UpdateTransactionHandler;
use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Entity\Transaction;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use App\Module\Budget\Domain\Repository\TransactionRepositoryInterface;
use App\Module\Budget\Domain\ValueObject\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UpdateTransactionHandlerTest extends TestCase
{
    public function testUpdatesTransaction(): void
    {
        $transaction = new Transaction('t-1', new Money(1000, 'PLN'), new DateTimeImmutable('2026-07-01'), 'c-old', TransactionType::EXPENSE);

        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn(new Category('c-new', 'Salary', TransactionType::INCOME));

        $transactions = $this->createMock(TransactionRepositoryInterface::class);
        $transactions->method('findById')->willReturn($transaction);
        $transactions->expects(self::once())->method('save')->with(self::callback(
            fn (Transaction $t): bool => 500000 === $t->amount()->amountInCents()
                && 'c-new' === $t->categoryId()
                && TransactionType::INCOME === $t->type()
                && 'Bonus' === $t->description()
        ));

        $handler = new UpdateTransactionHandler($transactions, $categories);
        $handler(new UpdateTransaction('t-1', 500000, 'PLN', '2026-07-20', 'c-new', 'income', 'Bonus'));
    }

    public function testThrowsWhenTransactionNotFound(): void
    {
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $transactions = $this->createMock(TransactionRepositoryInterface::class);
        $transactions->method('findById')->willReturn(null);
        $transactions->expects(self::never())->method('save');

        $handler = new UpdateTransactionHandler($transactions, $categories);

        $this->expectException(TransactionNotFoundException::class);
        $handler(new UpdateTransaction('missing', 1000, 'PLN', '2026-07-20', 'c-1', 'expense'));
    }

    public function testThrowsWhenCategoryNotFoundWithoutSaving(): void
    {
        $transaction = new Transaction('t-1', new Money(1000, 'PLN'), new DateTimeImmutable('2026-07-01'), 'c-old', TransactionType::EXPENSE);

        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn(null);

        $transactions = $this->createMock(TransactionRepositoryInterface::class);
        $transactions->method('findById')->willReturn($transaction);
        $transactions->expects(self::never())->method('save');

        $handler = new UpdateTransactionHandler($transactions, $categories);

        $this->expectException(CategoryNotFoundException::class);
        $handler(new UpdateTransaction('t-1', 1000, 'PLN', '2026-07-20', 'missing', 'expense'));
    }

    public function testThrowsOnInvalidTypeWithoutSaving(): void
    {
        $transaction = new Transaction('t-1', new Money(1000, 'PLN'), new DateTimeImmutable('2026-07-01'), 'c-old', TransactionType::EXPENSE);

        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn(new Category('c-1', 'Groceries', TransactionType::EXPENSE));

        $transactions = $this->createMock(TransactionRepositoryInterface::class);
        $transactions->method('findById')->willReturn($transaction);
        $transactions->expects(self::never())->method('save');

        $handler = new UpdateTransactionHandler($transactions, $categories);

        $this->expectException(InvalidArgumentException::class);
        $handler(new UpdateTransaction('t-1', 1000, 'PLN', '2026-07-20', 'c-1', 'not-a-type'));
    }
}
