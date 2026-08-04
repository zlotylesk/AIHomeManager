<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\SetMonthlyLimit;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Application\SystemCurrency;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use App\Module\Budget\Domain\ValueObject\Money;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SetMonthlyLimitHandler
{
    public function __construct(
        private CategoryRepositoryInterface $categories,
        private SystemCurrency $currency,
    ) {
    }

    public function __invoke(SetMonthlyLimit $command): void
    {
        $category = $this->categories->findById($command->id);
        if (null === $category) {
            throw new CategoryNotFoundException(sprintf('Category "%s" not found.', $command->id));
        }

        if (null === $command->amountInCents && null === $command->currency) {
            $category->setMonthlyLimit(null);
        } elseif (null !== $command->amountInCents && null !== $command->currency) {
            // A limit in another currency is refused for the same reason a
            // transaction is: the report compares the two, and comparing them
            // across currencies is how a category limited to 500 PLN reported
            // 80% used against 400 EUR of spending.
            $limit = new Money($command->amountInCents, $command->currency);
            $this->currency->assertSupported($limit);
            $category->setMonthlyLimit($limit);
        } else {
            throw new InvalidArgumentException('Monthly limit amount and currency must be both set or both null.');
        }

        $this->categories->save($category);
    }
}
