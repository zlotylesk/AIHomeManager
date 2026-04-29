<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Handler;

use App\Module\Books\Application\Command\LogReadingSession;
use App\Module\Books\Domain\Entity\ReadingSession;
use App\Module\Books\Domain\Repository\BookRepositoryInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class LogReadingSessionHandler
{
    public function __construct(
        private BookRepositoryInterface $bookRepository,
        private Connection $connection,
    ) {}

    public function __invoke(LogReadingSession $command): void
    {
        $book = $this->bookRepository->findById($command->bookId);

        if ($book === null) {
            throw new \DomainException('Book not found.');
        }

        $session = new ReadingSession(
            id: Uuid::v4()->toRfc4122(),
            bookId: $command->bookId,
            date: new \DateTimeImmutable($command->date),
            pagesRead: $command->pagesRead,
            notes: $command->notes,
        );

        $book->addReadingSession($session);
        $this->bookRepository->save($book);

        $this->connection->insert('book_reading_sessions', [
            'id' => $session->id(),
            'book_id' => $session->bookId(),
            'date' => $session->date()->format('Y-m-d'),
            'pages_read' => $session->pagesRead(),
            'notes' => $session->notes(),
        ]);
    }
}
