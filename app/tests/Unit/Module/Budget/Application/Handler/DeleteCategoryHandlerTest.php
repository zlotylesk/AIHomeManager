<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\DeleteCategory;
use App\Module\Budget\Application\Exception\CategoryHasTransactionsException;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Application\Handler\DeleteCategoryHandler;
use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use App\Module\Budget\Domain\Repository\TransactionRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class DeleteCategoryHandlerTest extends TestCase
{
    public function testRemovesCategoryWithoutTransactions(): void
    {
        $category = new Category('c-1', 'Groceries', TransactionType::EXPENSE);

        $categories = $this->createMock(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn($category);
        $categories->expects(self::once())->method('remove')->with($category);

        $transactions = $this->createStub(TransactionRepositoryInterface::class);
        $transactions->method('existsForCategory')->willReturn(false);

        $handler = new DeleteCategoryHandler($categories, $transactions);
        $handler(new DeleteCategory('c-1'));
    }

    public function testThrowsWhenCategoryNotFound(): void
    {
        $categories = $this->createMock(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn(null);
        $categories->expects(self::never())->method('remove');

        $transactions = $this->createMock(TransactionRepositoryInterface::class);
        $transactions->expects(self::never())->method('existsForCategory');

        $handler = new DeleteCategoryHandler($categories, $transactions);

        $this->expectException(CategoryNotFoundException::class);
        $handler(new DeleteCategory('missing'));
    }

    public function testThrowsWhenCategoryHasTransactionsWithoutRemoving(): void
    {
        $category = new Category('c-1', 'Groceries', TransactionType::EXPENSE);

        $categories = $this->createMock(CategoryRepositoryInterface::class);
        $categories->method('findById')->willReturn($category);
        $categories->expects(self::never())->method('remove');

        $transactions = $this->createStub(TransactionRepositoryInterface::class);
        $transactions->method('existsForCategory')->willReturn(true);

        $handler = new DeleteCategoryHandler($categories, $transactions);

        $this->expectException(CategoryHasTransactionsException::class);
        $handler(new DeleteCategory('c-1'));
    }
}
