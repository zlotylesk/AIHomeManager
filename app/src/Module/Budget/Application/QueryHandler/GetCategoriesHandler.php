<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\QueryHandler;

use App\Module\Budget\Application\DTO\CategoryDTO;
use App\Module\Budget\Application\MoneyColumn;
use App\Module\Budget\Application\Query\GetCategories;
use App\Shared\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCategoriesHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return Page<CategoryDTO> */
    public function __invoke(GetCategories $query): Page
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM budget_categories');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, type, monthly_limit FROM budget_categories ORDER BY name ASC, id ASC LIMIT :limit OFFSET :offset',
            ['limit' => $query->page->perPage, 'offset' => $query->page->offset()],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return Page::of(array_map($this->toDTO(...), $rows), $total, $query->page);
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
