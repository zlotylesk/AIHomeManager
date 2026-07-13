<?php

declare(strict_types=1);

namespace App\Module\Dashboard\Infrastructure\Provider;

use App\Module\Dashboard\Domain\ReadModel\Recommendation;
use Doctrine\DBAL\Connection;

/**
 * Reads "continue" suggestions straight from the `series` (ongoing shows) and
 * `books` (currently reading) tables via DBAL — no import of any Series or Books
 * class, keeping those Dashboard boundaries deptrac-clean.
 */
final readonly class RecommendationsAdapter
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return Recommendation[]
     */
    public function recommendations(int $limit): array
    {
        $cap = max(0, $limit);

        $seriesRows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT title, cover_url, year FROM series WHERE status = :status '
                .'ORDER BY created_at DESC LIMIT %d',
                $cap,
            ),
            ['status' => 'ongoing'],
        );

        $bookRows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT title, author, cover_url FROM books WHERE status = :status '
                .'ORDER BY title ASC LIMIT %d',
                $cap,
            ),
            ['status' => 'reading'],
        );

        $recommendations = [];
        foreach ($seriesRows as $row) {
            $recommendations[] = new Recommendation(
                'series',
                (string) $row['title'],
                null !== $row['cover_url'] ? (string) $row['cover_url'] : null,
                null !== $row['year'] ? (string) $row['year'] : null,
            );
        }

        foreach ($bookRows as $row) {
            $recommendations[] = new Recommendation(
                'book',
                (string) $row['title'],
                null !== $row['cover_url'] ? (string) $row['cover_url'] : null,
                (string) $row['author'],
            );
        }

        return $recommendations;
    }
}
