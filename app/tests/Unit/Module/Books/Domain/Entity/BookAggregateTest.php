<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Books\Domain\Entity;

use App\Module\Books\Domain\Entity\Book;
use App\Module\Books\Domain\Entity\ReadingSession;
use App\Module\Books\Domain\Enum\BookStatus;
use App\Module\Books\Domain\Event\BookCompleted;
use App\Module\Books\Domain\ValueObject\ISBN;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BookAggregateTest extends TestCase
{
    public function testAddReadingSessionEmitsBookCompletedOnceWhenReadingFinishes(): void
    {
        $book = $this->makeBook(totalPages: 100);

        $book->addReadingSession($this->makeSession(pagesRead: 60));
        self::assertSame([], $book->releaseEvents(), 'Partial reads must not record BookCompleted.');
        self::assertSame(BookStatus::READING, $book->status());

        $book->addReadingSession($this->makeSession(pagesRead: 40));

        $events = $book->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(BookCompleted::class, $events[0]);
        self::assertSame('book-id', $events[0]->bookId);
        self::assertSame(BookStatus::COMPLETED, $book->status());
    }

    public function testReleaseEventsDrainsTheRecordedList(): void
    {
        $book = $this->makeBook(totalPages: 10);
        $book->addReadingSession($this->makeSession(pagesRead: 10));

        $firstDrain = $book->releaseEvents();
        $secondDrain = $book->releaseEvents();

        self::assertCount(1, $firstDrain);
        self::assertSame([], $secondDrain);
    }

    public function testBookCompletedIsNotReEmittedOnSubsequentSessionsAgainstCompletedBook(): void
    {
        $book = $this->makeBook(totalPages: 10);
        $book->addReadingSession($this->makeSession(pagesRead: 10));
        $firstDrain = $book->releaseEvents();
        self::assertCount(1, $firstDrain);

        $book->addReadingSession($this->makeSession(pagesRead: 0));

        self::assertSame([], $book->releaseEvents(), 'Already-completed book must not re-emit BookCompleted.');
    }

    public function testStartingReadingDoesNotEmitBookCompleted(): void
    {
        $book = $this->makeBook(totalPages: 100);
        $book->addReadingSession($this->makeSession(pagesRead: 1));

        self::assertSame(BookStatus::READING, $book->status());
        self::assertSame([], $book->releaseEvents());
    }

    private function makeBook(int $totalPages): Book
    {
        return new Book(
            id: 'book-id',
            isbn: new ISBN('9780000000002'),
            title: 'Test',
            author: 'Author',
            publisher: 'Publisher',
            year: 2024,
            coverUrl: null,
            totalPages: $totalPages,
        );
    }

    private function makeSession(int $pagesRead): ReadingSession
    {
        return new ReadingSession(
            id: 'session-id',
            bookId: 'book-id',
            date: new DateTimeImmutable('2026-05-22 12:00:00'),
            pagesRead: $pagesRead,
        );
    }
}
