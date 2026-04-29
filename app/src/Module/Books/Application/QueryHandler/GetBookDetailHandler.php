<?php

declare(strict_types=1);

namespace App\Module\Books\Application\QueryHandler;

use App\Module\Books\Application\DTO\BookDTO;
use App\Module\Books\Application\Query\GetBookDetail;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetBookDetailHandler
{
    public function __construct(private Connection $connection) {}

    public function __invoke(GetBookDetail $query): ?BookDTO
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, isbn, title, author, publisher, year, cover_url, total_pages, current_page, status
             FROM books WHERE id = :id',
            ['id' => $query->id]
        );

        if ($row === false) {
            return null;
        }

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
