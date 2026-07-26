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

        return array_map($this->toDocument(...), $rows);
    }

    public function documentFor(SearchResultType $type, string $id): ?SearchableDocument
    {
        if (SearchResultType::BOOK !== $type) {
            return null;
        }

        $row = $this->connection->fetchAssociative('SELECT id, title, author FROM books WHERE id = ?', [$id]);

        return false === $row ? null : $this->toDocument($row);
    }

    /**
     * The single row-to-document mapping, shared by the bulk read and the
     * single-document lookup so the two cannot drift into indexing different
     * shapes for the same entity.
     *
     * @param array<string, mixed> $row
     */
    private function toDocument(array $row): SearchableDocument
    {
        return new SearchableDocument(
            SearchResultType::BOOK,
            (string) $row['id'],
            (string) $row['title'],
            (string) $row['author'],
            '/books',
        );
    }
}
