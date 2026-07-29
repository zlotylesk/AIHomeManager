<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\DeleteCategory;
use App\Module\Budget\Application\Exception\CategoryHasTransactionsException;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use App\Module\Budget\Domain\Repository\TransactionRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deleting a category with at least one transaction attributed to it is a
 * hard block (409) — deliberately neither a cascade nor a reassignment to
 * "no category". Cascading would silently destroy ledger history in a
 * personal finance app, the one place silent data loss must never happen;
 * reassigning to "no category" would need Transaction::$categoryId to become
 * nullable, a bigger schema change this ticket does not otherwise need. The
 * user re-files or deletes the transactions themselves first.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeleteCategoryHandler
{
    public function __construct(
        private CategoryRepositoryInterface $categories,
        private TransactionRepositoryInterface $transactions,
    ) {
    }

    public function __invoke(DeleteCategory $command): void
    {
        $category = $this->categories->findById($command->id);
        if (null === $category) {
            throw new CategoryNotFoundException(sprintf('Category "%s" not found.', $command->id));
        }

        if ($this->transactions->existsForCategory($command->id)) {
            throw new CategoryHasTransactionsException(sprintf('Category "%s" has transactions and cannot be deleted.', $command->id));
        }

        $this->categories->remove($category);
    }
}
