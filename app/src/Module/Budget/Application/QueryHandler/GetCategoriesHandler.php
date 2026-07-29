<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\QueryHandler;

use App\Module\Budget\Application\DTO\CategoryDTO;
use App\Module\Budget\Application\MoneyColumn;
use App\Module\Budget\Application\Query\GetCategories;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCategoriesHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return CategoryDTO[] */
    public function __invoke(GetCategories $query): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, name, type, monthly_limit FROM budget_categories ORDER BY name ASC');

        return array_map($this->toDTO(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toDTO(array $row): CategoryDTO
    {
        $amountInCents = null;
        $currency = null;
        if (null !== $row['monthly_limit']) {
            [$amountInCents, $currency] = MoneyColumn::parse((string) $row['monthly_limit']);
        }

        return new CategoryDTO(
            id: (string) $row['id'],
            name: (string) $row['name'],
            type: (string) $row['type'],
            monthlyLimitAmountInCents: $amountInCents,
            monthlyLimitCurrency: $currency,
        );
    }
}
