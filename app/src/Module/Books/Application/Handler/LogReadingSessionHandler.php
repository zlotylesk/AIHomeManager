<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Handler;

use App\Module\Books\Application\Command\LogReadingSession;
use App\Module\Books\Domain\Entity\ReadingSession;
use App\Module\Books\Domain\Repository\BookRepositoryInterface;
use DateTimeImmutable;
use DomainException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class LogReadingSessionHandler
{
    public function __construct(
        private BookRepositoryInterface $bookRepository,
    ) {
    }

    public function __invoke(LogReadingSession $command): void
    {
        $book = $this->bookRepository->findById($command->bookId);

        if (null === $book) {
            throw new DomainException('Book not found.');
        }

        $session = new ReadingSession(
            id: Uuid::v4()->toRfc4122(),
            bookId: $command->bookId,
            date: new DateTimeImmutable($command->date),
            pagesRead: $command->pagesRead,
            notes: $command->notes,
        );

        $book->addReadingSession($session);
        $this->bookRepository->save($book);
    }
}
