<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Provider;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchableProviderInterface;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use Doctrine\DBAL\Connection;

/**
 * Exposes Music as indexable documents by reading the `music_listening_sessions`
 * table via DBAL. Listening history holds one row per play, so albums are
 * de-duplicated by artist+title (one document per album). Raw SQL imports no
 * Music class, keeping the Search ← Music boundary deptrac-clean.
 */
final readonly class MusicSearchableProvider implements SearchableProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function documents(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT MIN(id) AS id, artist, title FROM music_listening_sessions GROUP BY artist, title',
        );

        return array_map(
            static fn (array $row): SearchableDocument => new SearchableDocument(
                SearchResultType::MUSIC,
                (string) $row['id'],
                (string) $row['title'],
                (string) $row['artist'],
                '/music',
            ),
            $rows,
        );
    }
}
