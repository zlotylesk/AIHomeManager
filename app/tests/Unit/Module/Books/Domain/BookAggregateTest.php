<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Books\Domain;

use App\Module\Books\Domain\Entity\Book;
use App\Module\Books\Domain\Entity\ReadingSession;
use App\Module\Books\Domain\Enum\BookStatus;
use App\Module\Books\Domain\ValueObject\ISBN;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class BookAggregateTest extends TestCase
{
    private function makeBook(int $totalPages = 300): Book
    {
        return new Book(
            id: 'book-uuid-1',
            isbn: new ISBN('9780306406157'),
            title: 'Clean Code',
            author: 'Robert C. Martin',
            publisher: 'Prentice Hall',
            year: 2008,
            coverUrl: null,
            totalPages: $totalPages,
        );
    }

    private function makeSession(string $bookId, int $pagesRead, ?string $notes = null): ReadingSession
    {
        return new ReadingSession(
            id: 'session-uuid-1',
            bookId: $bookId,
            date: new DateTimeImmutable('2025-01-15'),
            pagesRead: $pagesRead,
            notes: $notes,
        );
    }

    public function testNewBookHasToReadStatus(): void
    {
        $book = $this->makeBook();

        self::assertSame(BookStatus::TO_READ, $book->status());
    }

    public function testNewBookHasZeroCurrentPage(): void
    {
        $book = $this->makeBook();

        self::assertSame(0, $book->readingProgress()->currentPage());
    }

    public function testFirstSessionChangesStatusToReading(): void
    {
        $book = $this->makeBook();

        $book->addReadingSession($this->makeSession($book->id(), 50));

        self::assertSame(BookStatus::READING, $book->status());
    }

    public function testAddReadingSessionUpdatesCurrentPage(): void
    {
        $book = $this->makeBook(300);

        $book->addReadingSession($this->makeSession($book->id(), 100));

        self::assertSame(100, $book->readingProgress()->currentPage());
    }

    public function testMultipleSessionsAccumulatePages(): void
    {
        $book = $this->makeBook(300);

        $book->addReadingSession($this->makeSession($book->id(), 100));
        $book->addReadingSession(new ReadingSession('s2', $book->id(), new DateTimeImmutable(), 100));

        self::assertSame(200, $book->readingProgress()->currentPage());
    }

    public function testSessionCompletingBookChangesStatusToCompleted(): void
    {
        $book = $this->makeBook(100);

        $book->addReadingSession($this->makeSession($book->id(), 100));

        self::assertSame(BookStatus::COMPLETED, $book->status());
    }

    public function testAddReadingSessionThrowsWhenExceedingTotalPages(): void
    {
        $book = $this->makeBook(100);

        $this->expectException(DomainException::class);

        $book->addReadingSession($this->makeSession($book->id(), 150));
    }

    public function testReleaseEventsClearsCollection(): void
    {
        $book = $this->makeBook();

        $book->releaseEvents();

        self::assertEmpty($book->releaseEvents());
    }
}
