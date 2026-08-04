<?php

declare(strict_types=1);

namespace App\Module\Books\Application\QueryHandler;

use App\Module\Books\Application\DTO\BookDTO;
use App\Module\Books\Application\Query\GetAllBooks;
use App\Shared\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAllBooksHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return Page<BookDTO> */
    public function __invoke(GetAllBooks $query): Page
    {
        $where = '';
        $params = [];

        if (null !== $query->status) {
            $where = ' WHERE status = :status';
            $params['status'] = $query->status;
        }

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM books'.$where, $params);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, isbn, title, author, publisher, year, cover_url, total_pages, current_page, status
                FROM books'.$where.' ORDER BY title ASC, id ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $query->page->perPage, 'offset' => $query->page->offset()],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return Page::of(array_map($this->toDTO(...), $rows), $total, $query->page);
    }

    /**
     * @param array<string, mixed> $row
     */
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
