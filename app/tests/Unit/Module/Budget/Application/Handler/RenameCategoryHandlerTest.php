<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\RenameCategory;
use App\Module\Budget\Application\Exception\CategoryNameAlreadyTakenException;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Application\Handler\RenameCategoryHandler;
use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class RenameCategoryHandlerTest extends TestCase
{
    public function testRenamesCategory(): void
    {
        $category = new Category('c-1', 'Old Name', TransactionType::EXPENSE);

        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('findById')->willReturn($category);
        $repo->method('findByNameAndType')->willReturn(null);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Category $c): bool => 'New Name' === $c->name()
        ));

        $handler = new RenameCategoryHandler($repo);
        $handler(new RenameCategory('c-1', 'New Name'));
    }

    public function testAllowsRenamingToItsOwnCurrentName(): void
    {
        $category = new Category('c-1', 'Same Name', TransactionType::EXPENSE);

        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('findById')->willReturn($category);
        $repo->method('findByNameAndType')->willReturn($category);
        $repo->expects(self::once())->method('save');

        $handler = new RenameCategoryHandler($repo);
        $handler(new RenameCategory('c-1', 'Same Name'));
    }

    public function testThrowsWhenCategoryNotFound(): void
    {
        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $handler = new RenameCategoryHandler($repo);

        $this->expectException(CategoryNotFoundException::class);
        $handler(new RenameCategory('missing', 'New Name'));
    }

    public function testThrowsWhenNameTakenByAnotherCategoryOfTheSameType(): void
    {
        $category = new Category('c-1', 'Old Name', TransactionType::EXPENSE);
        $other = new Category('c-2', 'Taken Name', TransactionType::EXPENSE);

        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('findById')->willReturn($category);
        $repo->method('findByNameAndType')->willReturn($other);
        $repo->expects(self::never())->method('save');

        $handler = new RenameCategoryHandler($repo);

        $this->expectException(CategoryNameAlreadyTakenException::class);
        $handler(new RenameCategory('c-1', 'Taken Name'));
    }
}
