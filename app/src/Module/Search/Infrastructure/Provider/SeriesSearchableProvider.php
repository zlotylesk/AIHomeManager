<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Provider;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchableProviderInterface;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use Doctrine\DBAL\Connection;

/**
 * Exposes Series as indexable documents by reading the `series` table via DBAL.
 * Raw SQL imports no Series class, so the Search ← Series boundary stays
 * deptrac-clean — the same DBAL-for-reads rule the query handlers follow.
 */
final readonly class SeriesSearchableProvider implements SearchableProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function documents(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, title, description FROM series');

        return array_map(
            static fn (array $row): SearchableDocument => new SearchableDocument(
                SearchResultType::SERIES,
                (string) $row['id'],
                (string) $row['title'],
                (string) ($row['description'] ?? ''),
                '/series',
            ),
            $rows,
        );
    }
}
