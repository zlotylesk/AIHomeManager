<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\SetMonthlyLimit;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Application\Handler\SetMonthlyLimitHandler;
use App\Module\Budget\Application\SystemCurrency;
use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use App\Module\Budget\Domain\ValueObject\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SetMonthlyLimitHandlerTest extends TestCase
{
    public function testSetsTheLimit(): void
    {
        $category = new Category('c-1', 'Groceries', TransactionType::EXPENSE);

        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('findById')->willReturn($category);
        $repo->expects(self::once())->method('save')->with(self::callback(
            function (Category $c): bool {
                $limit = $c->monthlyLimit();

                return null !== $limit && 50000 === $limit->amountInCents() && 'PLN' === $limit->currency();
            }
        ));

        $handler = new SetMonthlyLimitHandler($repo, new SystemCurrency());
        $handler(new SetMonthlyLimit('c-1', 50000, 'PLN'));
    }

    public function testClearsTheLimitWhenBothFieldsAreNull(): void
    {
        $category = new Category('c-1', 'Groceries', TransactionType::EXPENSE, new Money(50000, 'PLN'));

        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('findById')->willReturn($category);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Category $c): bool => null === $c->monthlyLimit()
        ));

        $handler = new SetMonthlyLimitHandler($repo, new SystemCurrency());
        $handler(new SetMonthlyLimit('c-1', null, null));
    }

    public function testThrowsWhenCategoryNotFound(): void
    {
        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $handler = new SetMonthlyLimitHandler($repo, new SystemCurrency());

        $this->expectException(CategoryNotFoundException::class);
        $handler(new SetMonthlyLimit('missing', 50000, 'PLN'));
    }

    public function testThrowsWhenOnlyAmountIsGivenWithoutSaving(): void
    {
        $category = new Category('c-1', 'Groceries', TransactionType::EXPENSE);

        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('findById')->willReturn($category);
        $repo->expects(self::never())->method('save');

        $handler = new SetMonthlyLimitHandler($repo, new SystemCurrency());

        $this->expectException(InvalidArgumentException::class);
        $handler(new SetMonthlyLimit('c-1', 50000, null));
    }

    public function testThrowsWhenOnlyCurrencyIsGivenWithoutSaving(): void
    {
        $category = new Category('c-1', 'Groceries', TransactionType::EXPENSE);

        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('findById')->willReturn($category);
        $repo->expects(self::never())->method('save');

        $handler = new SetMonthlyLimitHandler($repo, new SystemCurrency());

        $this->expectException(InvalidArgumentException::class);
        $handler(new SetMonthlyLimit('c-1', null, 'PLN'));
    }
}
