<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Handler;

use App\Module\Books\Application\Command\UpdateBook;
use App\Module\Books\Domain\Repository\BookRepositoryInterface;
use DomainException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class UpdateBookHandler
{
    public function __construct(private BookRepositoryInterface $bookRepository)
    {
    }

    public function __invoke(UpdateBook $command): void
    {
        $book = $this->bookRepository->findById($command->id);

        if (null === $book) {
            throw new DomainException('Book not found.');
        }

        $book->updateMetadata(
            title: $command->title,
            author: $command->author,
            publisher: $command->publisher,
            year: $command->year,
            coverUrl: $command->coverUrl,
        );

        $this->bookRepository->save($book);
    }
}
