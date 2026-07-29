<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Domain;

use App\Module\Budget\Domain\Entity\Transaction;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\ValueObject\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    public function testConstructsWithProvidedAttributes(): void
    {
        $date = new DateTimeImmutable('2026-07-15');
        $amount = new Money(4999);

        $transaction = new Transaction('t-0001', $amount, $date, 'c-groceries', TransactionType::EXPENSE, 'Weekly shop');

        self::assertSame('t-0001', $transaction->id());
        self::assertSame($amount, $transaction->amount());
        self::assertSame($date, $transaction->date());
        self::assertSame('c-groceries', $transaction->categoryId());
        self::assertSame(TransactionType::EXPENSE, $transaction->type());
        self::assertSame('Weekly shop', $transaction->description());
    }

    public function testDescriptionIsOptional(): void
    {
        $transaction = new Transaction('t-0002', new Money(100), new DateTimeImmutable(), 'c-salary', TransactionType::INCOME);

        self::assertNull($transaction->description());
    }

    public function testThrowsWhenIdIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transaction id cannot be empty.');

        new Transaction('', new Money(100), new DateTimeImmutable(), 'c-salary', TransactionType::INCOME);
    }

    public function testThrowsWhenIdIsWhitespace(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Transaction("  \t", new Money(100), new DateTimeImmutable(), 'c-salary', TransactionType::INCOME);
    }

    public function testThrowsWhenCategoryIdIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transaction category id cannot be empty.');

        new Transaction('t-0003', new Money(100), new DateTimeImmutable(), '', TransactionType::INCOME);
    }

    public function testUpdateReplacesAllEditableFields(): void
    {
        $transaction = new Transaction('t-0004', new Money(100), new DateTimeImmutable('2026-01-01'), 'c-old', TransactionType::EXPENSE, 'Old');

        $newAmount = new Money(999900, 'EUR');
        $newDate = new DateTimeImmutable('2026-07-20');
        $transaction->update($newAmount, $newDate, 'c-new', TransactionType::INCOME, 'New');

        self::assertSame($newAmount, $transaction->amount());
        self::assertSame($newDate, $transaction->date());
        self::assertSame('c-new', $transaction->categoryId());
        self::assertSame(TransactionType::INCOME, $transaction->type());
        self::assertSame('New', $transaction->description());
    }

    public function testUpdateLeavesIdUntouched(): void
    {
        $transaction = new Transaction('t-0005', new Money(100), new DateTimeImmutable(), 'c-old', TransactionType::EXPENSE);

        $transaction->update(new Money(200), new DateTimeImmutable(), 'c-new', TransactionType::INCOME, null);

        self::assertSame('t-0005', $transaction->id());
    }

    public function testUpdateRejectsEmptyCategoryId(): void
    {
        $transaction = new Transaction('t-0006', new Money(100), new DateTimeImmutable(), 'c-old', TransactionType::EXPENSE);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Transaction category id cannot be empty.');

        $transaction->update(new Money(100), new DateTimeImmutable(), '', TransactionType::EXPENSE, null);
    }

    public function testUpdateCanClearDescription(): void
    {
        $transaction = new Transaction('t-0007', new Money(100), new DateTimeImmutable(), 'c-old', TransactionType::EXPENSE, 'Has a note');

        $transaction->update(new Money(100), new DateTimeImmutable(), 'c-old', TransactionType::EXPENSE, null);

        self::assertNull($transaction->description());
    }
}
