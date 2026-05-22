<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Books\Application\Handler;

use App\Module\Books\Application\Command\LogReadingSession;
use App\Module\Books\Application\Exception\BookNotFoundException;
use App\Module\Books\Application\Handler\LogReadingSessionHandler;
use App\Module\Books\Domain\Entity\Book;
use App\Module\Books\Domain\Event\BookCompleted;
use App\Module\Books\Domain\Repository\BookRepositoryInterface;
use App\Module\Books\Domain\ValueObject\ISBN;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class LogReadingSessionHandlerTest extends TestCase
{
    public function testDispatchesBookCompletedOnFinalSession(): void
    {
        $book = new Book(
            id: 'book-id',
            isbn: new ISBN('9780000000002'),
            title: 'Test',
            author: 'Author',
            publisher: 'Publisher',
            year: 2024,
            coverUrl: null,
            totalPages: 10,
        );

        $repository = $this->createMock(BookRepositoryInterface::class);
        $repository->expects(self::once())->method('findById')->with('book-id')->willReturn($book);
        $repository->expects(self::once())->method('save')->with($book);

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(BookCompleted::class))
            ->willReturnCallback(fn (BookCompleted $event): Envelope => new Envelope($event));

        $handler = new LogReadingSessionHandler($repository, $eventBus);

        $handler(new LogReadingSession(
            bookId: 'book-id',
            pagesRead: 10,
            date: '2026-05-22',
        ));
    }

    public function testDoesNotDispatchOnPartialSession(): void
    {
        $book = new Book(
            id: 'book-id',
            isbn: new ISBN('9780000000002'),
            title: 'Test',
            author: 'Author',
            publisher: 'Publisher',
            year: 2024,
            coverUrl: null,
            totalPages: 100,
        );

        $repository = $this->createMock(BookRepositoryInterface::class);
        $repository->expects(self::once())->method('findById')->willReturn($book);
        $repository->expects(self::once())->method('save');

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects(self::never())->method('dispatch');

        $handler = new LogReadingSessionHandler($repository, $eventBus);

        $handler(new LogReadingSession(
            bookId: 'book-id',
            pagesRead: 50,
            date: '2026-05-22',
        ));
    }

    public function testThrowsBookNotFoundExceptionAndDoesNotDispatchWhenBookMissing(): void
    {
        $repository = $this->createMock(BookRepositoryInterface::class);
        $repository->expects(self::once())->method('findById')->willReturn(null);
        $repository->expects(self::never())->method('save');

        $eventBus = $this->createMock(MessageBusInterface::class);
        $eventBus->expects(self::never())->method('dispatch');

        $handler = new LogReadingSessionHandler($repository, $eventBus);

        $this->expectException(BookNotFoundException::class);

        $handler(new LogReadingSession(
            bookId: 'missing',
            pagesRead: 10,
            date: '2026-05-22',
        ));
    }
}
