<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Provider;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchableProviderInterface;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use Doctrine\DBAL\Connection;

/**
 * Exposes Articles as indexable documents by reading the `articles` table via
 * DBAL. Raw SQL imports no Articles class, so the Search ← Articles boundary
 * stays deptrac-clean — the same DBAL-for-reads rule the query handlers follow.
 */
final readonly class ArticlesSearchableProvider implements SearchableProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function documents(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, title, category FROM articles');

        return array_map(
            static fn (array $row): SearchableDocument => new SearchableDocument(
                SearchResultType::ARTICLE,
                (string) $row['id'],
                (string) $row['title'],
                (string) ($row['category'] ?? ''),
                '/articles',
            ),
            $rows,
        );
    }
}
