<?php

declare(strict_types=1);

namespace App\Module\Books\Application\QueryHandler;

use App\Module\Books\Application\DTO\BookDTO;
use App\Module\Books\Application\Query\GetAllBooks;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAllBooksHandler
{
    public function __construct(private Connection $connection) {}

    /** @return BookDTO[] */
    public function __invoke(GetAllBooks $query): array
    {
        $sql = 'SELECT id, isbn, title, author, publisher, year, cover_url, total_pages, current_page, status
                FROM books';

        $params = [];

        if ($query->status !== null) {
            $sql .= ' WHERE status = :status';
            $params['status'] = $query->status;
        }

        $sql .= ' ORDER BY title ASC';

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map($this->toDTO(...), $rows);
    }

    private function toDTO(array $row): BookDTO
    {
        $totalPages = (int) $row['total_pages'];
        $currentPage = (int) $row['current_page'];

        return new BookDTO(
            id: $row['id'],
            isbn: $row['isbn'],
            title: $row['title'],
            author: $row['author'],
            publisher: $row['publisher'],
            year: (int) $row['year'],
            coverUrl: $row['cover_url'],
            totalPages: $totalPages,
            currentPage: $currentPage,
            percentage: $totalPages > 0 ? round($currentPage / $totalPages * 100, 1) : 0.0,
            status: $row['status'],
        );
    }
}
