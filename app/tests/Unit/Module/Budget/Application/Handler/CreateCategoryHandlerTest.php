<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\CreateCategory;
use App\Module\Budget\Application\Exception\CategoryNameAlreadyTakenException;
use App\Module\Budget\Application\Handler\CreateCategoryHandler;
use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CreateCategoryHandlerTest extends TestCase
{
    public function testCreatesCategoryAndReturnsId(): void
    {
        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('findByNameAndType')->willReturn(null);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Category $c): bool => 'Groceries' === $c->name() && TransactionType::EXPENSE === $c->type()
        ));

        $handler = new CreateCategoryHandler($repo);
        $id = $handler(new CreateCategory('Groceries', 'expense'));

        self::assertNotEmpty($id);
    }

    public function testTrimsName(): void
    {
        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('findByNameAndType')->willReturn(null);
        $repo->expects(self::once())->method('save')->with(self::callback(
            fn (Category $c): bool => 'Groceries' === $c->name()
        ));

        $handler = new CreateCategoryHandler($repo);
        $handler(new CreateCategory('  Groceries  ', 'expense'));
    }

    public function testThrowsWhenNameAlreadyTakenForTypeWithoutSaving(): void
    {
        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->method('findByNameAndType')->willReturn(new Category('c-1', 'Groceries', TransactionType::EXPENSE));
        $repo->expects(self::never())->method('save');

        $handler = new CreateCategoryHandler($repo);

        $this->expectException(CategoryNameAlreadyTakenException::class);
        $handler(new CreateCategory('Groceries', 'expense'));
    }

    public function testThrowsOnUnknownTypeWithoutSaving(): void
    {
        $repo = $this->createMock(CategoryRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $handler = new CreateCategoryHandler($repo);

        $this->expectException(InvalidArgumentException::class);
        $handler(new CreateCategory('Groceries', 'not-a-type'));
    }
}
