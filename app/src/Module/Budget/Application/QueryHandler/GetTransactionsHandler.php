<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\QueryHandler;

use App\Module\Budget\Application\DTO\TransactionDTO;
use App\Module\Budget\Application\MoneyColumn;
use App\Module\Budget\Application\MonthRange;
use App\Module\Budget\Application\Query\GetTransactions;
use App\Module\Budget\Domain\Enum\TransactionType;
use App\Shared\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetTransactionsHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return Page<TransactionDTO> */
    public function __invoke(GetTransactions $query): Page
    {
        $conditions = [];
        $params = [];

        if (null !== $query->month) {
            $range = MonthRange::fromMonth($query->month);

            $conditions[] = 'date >= :monthStart AND date < :monthEnd';
            $params['monthStart'] = $range->startDate();
            $params['monthEnd'] = $range->endExclusiveDate();
        }

        if (null !== $query->categoryId) {
            $conditions[] = 'category_id = :categoryId';
            $params['categoryId'] = $query->categoryId;
        }

        if (null !== $query->type) {
            if (null === TransactionType::tryFrom($query->type)) {
                throw new InvalidArgumentException(sprintf('Unknown transaction type "%s".', $query->type));
            }

            $conditions[] = 'type = :type';
            $params['type'] = $query->type;
        }

        $where = [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions);

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM budget_transactions'.$where, $params);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, amount, date, category_id, type, description FROM budget_transactions'
                .$where.' ORDER BY date DESC, id ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $query->page->perPage, 'offset' => $query->page->offset()],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return Page::of(array_map($this->toDTO(...), $rows), $total, $query->page);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toDTO(array $row): TransactionDTO
    {
        [$amountInCents, $currency] = MoneyColumn::parse((string) $row['amount']);

        return new TransactionDTO(
            id: (string) $row['id'],
            amountInCents: $amountInCents,
            currency: $currency,
            date: (string) $row['date'],
            categoryId: (string) $row['category_id'],
            type: (string) $row['type'],
            description: null === $row['description'] ? null : (string) $row['description'],
        );
    }
}
