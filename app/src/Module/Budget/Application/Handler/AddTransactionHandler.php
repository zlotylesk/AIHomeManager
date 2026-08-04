<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\AddTransaction;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Application\SystemCurrency;
use App\Module\Budget\Application\TransactionCategoryMatch;
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
        private SystemCurrency $currency,
    ) {
    }

    public function __invoke(AddTransaction $command): string
    {
        $category = $this->categories->findById($command->categoryId);
        if (null === $category) {
            throw new CategoryNotFoundException(sprintf('Category "%s" not found.', $command->categoryId));
        }

        $input = TransactionInput::fromRaw($command->amountInCents, $command->currency, $command->date, $command->type);

        // Checked on the built VO rather than on the raw string, so the caller's
        // casing and padding are normalised the same way the stored amount is —
        // "pln" must not be refused where "PLN" is accepted.
        $this->currency->assertSupported($input->amount);

        TransactionCategoryMatch::assertTypesAgree($input->type, $category);

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
