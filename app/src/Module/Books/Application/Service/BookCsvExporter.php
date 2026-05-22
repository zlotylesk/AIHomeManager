<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Service;

use Doctrine\DBAL\Connection;
use Generator;

final readonly class BookCsvExporter
{
    /** @var list<string> */
    public const array HEADERS = ['isbn', 'title', 'author', 'status', 'percentage', 'totalPages'];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Streams book rows one at a time via DBAL cursor. Percentage computed in
     * PHP (not SQL) so an inadvertent total_pages=0 row can't crash the export
     * with a divide-by-zero — we degrade to 0.0 silently. Matches HMAI-36
     * dev notes on memory and `fetchAssociative()` loop.
     *
     * @return Generator<int, list<scalar|null>>
     */
    public function rows(): Generator
    {
        $sql = 'SELECT isbn, title, author, status, current_page, total_pages
                FROM books
                ORDER BY title ASC';

        $result = $this->connection->executeQuery($sql);
        while (false !== ($row = $result->fetchAssociative())) {
            $total = (int) $row['total_pages'];
            $current = (int) $row['current_page'];
            $percentage = $total > 0 ? round($current / $total * 100, 1) : 0.0;

            yield [
                $row['isbn'],
                $row['title'],
                $row['author'],
                $row['status'],
                $percentage,
                $total,
            ];
        }
    }
}
