<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Handler;

use App\Module\Budget\Application\Command\SetMonthlyLimit;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use App\Module\Budget\Domain\ValueObject\Money;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SetMonthlyLimitHandler
{
    public function __construct(private CategoryRepositoryInterface $categories)
    {
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
            $category->setMonthlyLimit(new Money($command->amountInCents, $command->currency));
        } else {
            throw new InvalidArgumentException('Monthly limit amount and currency must be both set or both null.');
        }

        $this->categories->save($category);
    }
}
