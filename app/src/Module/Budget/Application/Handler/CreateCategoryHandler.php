<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\CreateCategory;
use App\Module\Budget\Application\Exception\CategoryNameAlreadyTakenException;
use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateCategoryHandler
{
    public function __construct(private CategoryRepositoryInterface $categories)
    {
    }

    public function __invoke(CreateCategory $command): string
    {
        $type = TransactionType::tryFrom($command->type)
            ?? throw new InvalidArgumentException(sprintf('Unknown transaction type "%s".', $command->type));

        $name = trim($command->name);
        if (null !== $this->categories->findByNameAndType($name, $type)) {
            throw new CategoryNameAlreadyTakenException(sprintf('Category "%s" already exists for type "%s".', $name, $type->value));
        }

        $category = new Category(Uuid::v4()->toRfc4122(), $name, $type);

        $this->categories->save($category);

        return $category->id();
    }
}
