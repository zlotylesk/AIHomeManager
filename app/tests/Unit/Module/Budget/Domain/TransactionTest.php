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
}
