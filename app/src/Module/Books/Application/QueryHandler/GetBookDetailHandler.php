<?php

declare(strict_types=1);

namespace App\Module\Books\Application\QueryHandler;

use App\Module\Books\Application\DTO\BookDetailDTO;
use App\Module\Books\Application\DTO\BookDTO;
use App\Module\Books\Application\DTO\ReadingSessionDTO;
use App\Module\Books\Application\Query\GetBookDetail;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetBookDetailHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(GetBookDetail $query): ?BookDetailDTO
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, isbn, title, author, publisher, year, cover_url, total_pages, current_page, status
             FROM books WHERE id = :id',
            ['id' => $query->id]
        );

        if (false === $row) {
            return null;
        }

        $totalPages = (int) $row['total_pages'];
        $currentPage = (int) $row['current_page'];

        $book = new BookDTO(
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

        $sessionRows = $this->connection->fetchAllAssociative(
            'SELECT id, date, pages_read, notes
             FROM book_reading_sessions WHERE book_id = :id ORDER BY date DESC, id DESC',
            ['id' => $query->id]
        );

        $sessions = array_map(
            static fn (array $session): ReadingSessionDTO => new ReadingSessionDTO(
                id: $session['id'],
                date: $session['date'],
                pagesRead: (int) $session['pages_read'],
                notes: $session['notes'],
            ),
            $sessionRows,
        );

        return new BookDetailDTO(book: $book, sessions: $sessions);
    }
}
