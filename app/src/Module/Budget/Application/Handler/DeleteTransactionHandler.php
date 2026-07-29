<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\DeleteTransaction;
use App\Module\Budget\Application\Exception\TransactionNotFoundException;
use App\Module\Budget\Domain\Repository\TransactionRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeleteTransactionHandler
{
    public function __construct(private TransactionRepositoryInterface $transactions)
    {
    }

    public function __invoke(DeleteTransaction $command): void
    {
        $transaction = $this->transactions->findById($command->id);
        if (null === $transaction) {
            throw new TransactionNotFoundException(sprintf('Transaction "%s" not found.', $command->id));
        }

        $this->transactions->remove($transaction);
    }
}
