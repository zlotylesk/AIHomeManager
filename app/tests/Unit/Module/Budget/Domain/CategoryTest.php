<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Domain;

use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\ValueObject\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testConstructsWithProvidedAttributes(): void
    {
        $limit = new Money(50000);

        $category = new Category('c-0001', 'Zakupy spożywcze', TransactionType::EXPENSE, $limit);

        self::assertSame('c-0001', $category->id());
        self::assertSame('Zakupy spożywcze', $category->name());
        self::assertSame(TransactionType::EXPENSE, $category->type());
        self::assertSame($limit, $category->monthlyLimit());
    }

    public function testMonthlyLimitIsOptional(): void
    {
        $category = new Category('c-0002', 'Wynagrodzenie', TransactionType::INCOME);

        self::assertNull($category->monthlyLimit());
    }

    public function testTrimsName(): void
    {
        $category = new Category('c-0003', '  Rachunki  ', TransactionType::EXPENSE);

        self::assertSame('Rachunki', $category->name());
    }

    public function testThrowsWhenIdIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Category id cannot be empty.');

        new Category('', 'Rachunki', TransactionType::EXPENSE);
    }

    public function testThrowsWhenNameIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name cannot be empty.');

        new Category('c-0004', '   ', TransactionType::EXPENSE);
    }

    public function testRenameReplacesName(): void
    {
        $category = new Category('c-0005', 'Old Name', TransactionType::EXPENSE);

        $category->rename('New Name');

        self::assertSame('New Name', $category->name());
    }

    public function testRenameTrimsTheNewName(): void
    {
        $category = new Category('c-0006', 'Old Name', TransactionType::EXPENSE);

        $category->rename('  Trimmed  ');

        self::assertSame('Trimmed', $category->name());
    }

    public function testRenameRejectsEmptyName(): void
    {
        $category = new Category('c-0007', 'Old Name', TransactionType::EXPENSE);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Category name cannot be empty.');

        $category->rename('   ');
    }

    public function testSetMonthlyLimitStoresTheLimit(): void
    {
        $category = new Category('c-0008', 'Groceries', TransactionType::EXPENSE);
        $limit = new Money(50000, 'PLN');

        $category->setMonthlyLimit($limit);

        self::assertSame($limit, $category->monthlyLimit());
    }

    public function testSetMonthlyLimitNullClearsIt(): void
    {
        $category = new Category('c-0009', 'Groceries', TransactionType::EXPENSE, new Money(50000, 'PLN'));

        $category->setMonthlyLimit(null);

        self::assertNull($category->monthlyLimit());
    }

    public function testTypeIsImmutable(): void
    {
        $category = new Category('c-0010', 'Groceries', TransactionType::EXPENSE);

        $category->rename('Rent');
        $category->setMonthlyLimit(new Money(100000, 'PLN'));

        self::assertSame(TransactionType::EXPENSE, $category->type());
    }
}
