<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\AddTransaction;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Application\TransactionInput;
use App\Module\Budget\Domain\Entity\Transaction;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use App\Module\Budget\Domain\Repository\TransactionRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class AddTransactionHandler
{
    public function __construct(
        private TransactionRepositoryInterface $transactions,
        private CategoryRepositoryInterface $categories,
    ) {
    }

    public function __invoke(AddTransaction $command): string
    {
        if (null === $this->categories->findById($command->categoryId)) {
            throw new CategoryNotFoundException(sprintf('Category "%s" not found.', $command->categoryId));
        }

        $input = TransactionInput::fromRaw($command->amountInCents, $command->currency, $command->date, $command->type);

        $transaction = new Transaction(
            id: Uuid::v4()->toRfc4122(),
            amount: $input->amount,
            date: $input->date,
            categoryId: $command->categoryId,
            type: $input->type,
            description: $command->description,
        );

        $this->transactions->save($transaction);

        return $transaction->id();
    }
}
