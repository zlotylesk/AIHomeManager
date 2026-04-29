<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Handler;

use App\Module\Books\Application\Command\AddBook;
use App\Module\Books\Domain\Entity\Book;
use App\Module\Books\Domain\Repository\BookRepositoryInterface;
use App\Module\Books\Domain\ValueObject\ISBN;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class AddBookHandler
{
    public function __construct(private BookRepositoryInterface $bookRepository) {}

    public function __invoke(AddBook $command): string
    {
        $book = new Book(
            id: Uuid::v4()->toRfc4122(),
            isbn: new ISBN($command->isbn),
            title: $command->title,
            author: $command->author,
            publisher: $command->publisher,
            year: $command->year,
            coverUrl: $command->coverUrl,
            totalPages: $command->totalPages,
        );

        $this->bookRepository->save($book);

        return $book->id();
    }
}
