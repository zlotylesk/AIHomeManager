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
}
