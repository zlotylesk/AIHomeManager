<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Provider;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchableProviderInterface;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use Doctrine\DBAL\Connection;

/**
 * Exposes Books as indexable documents by reading the `books` table via DBAL.
 * Raw SQL imports no Books class, so the Search ← Books boundary stays
 * deptrac-clean — the same DBAL-for-reads rule the query handlers follow.
 */
final readonly class BooksSearchableProvider implements SearchableProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function documents(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, title, author FROM books');

        return array_map(
            static fn (array $row): SearchableDocument => new SearchableDocument(
                SearchResultType::BOOK,
                (string) $row['id'],
                (string) $row['title'],
                (string) $row['author'],
                '/books',
            ),
            $rows,
        );
    }
}
