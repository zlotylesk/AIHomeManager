<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\RenameCategory;
use App\Module\Budget\Application\Exception\CategoryNameAlreadyTakenException;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RenameCategoryHandler
{
    public function __construct(private CategoryRepositoryInterface $categories)
    {
    }

    public function __invoke(RenameCategory $command): void
    {
        $category = $this->categories->findById($command->id);
        if (null === $category) {
            throw new CategoryNotFoundException(sprintf('Category "%s" not found.', $command->id));
        }

        $name = trim($command->name);
        $existing = $this->categories->findByNameAndType($name, $category->type());
        if (null !== $existing && $existing->id() !== $category->id()) {
            throw new CategoryNameAlreadyTakenException(sprintf('Category "%s" already exists for type "%s".', $name, $category->type()->value));
        }

        $category->rename($name);

        $this->categories->save($category);
    }
}
