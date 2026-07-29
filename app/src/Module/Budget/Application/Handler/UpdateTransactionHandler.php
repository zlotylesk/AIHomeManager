<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\UpdateTransaction;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Application\Exception\TransactionNotFoundException;
use App\Module\Budget\Application\TransactionInput;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use App\Module\Budget\Domain\Repository\TransactionRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class UpdateTransactionHandler
{
    public function __construct(
        private TransactionRepositoryInterface $transactions,
        private CategoryRepositoryInterface $categories,
    ) {
    }

    public function __invoke(UpdateTransaction $command): void
    {
        $transaction = $this->transactions->findById($command->id);
        if (null === $transaction) {
            throw new TransactionNotFoundException(sprintf('Transaction "%s" not found.', $command->id));
        }

        if (null === $this->categories->findById($command->categoryId)) {
            throw new CategoryNotFoundException(sprintf('Category "%s" not found.', $command->categoryId));
        }

        $input = TransactionInput::fromRaw($command->amountInCents, $command->currency, $command->date, $command->type);

        $transaction->update($input->amount, $input->date, $command->categoryId, $input->type, $command->description);

        $this->transactions->save($transaction);
    }
}
