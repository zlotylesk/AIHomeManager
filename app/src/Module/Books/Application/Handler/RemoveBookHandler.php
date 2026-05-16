<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Handler;

use App\Module\Books\Application\Command\RemoveBook;
use App\Module\Books\Application\Exception\BookNotFoundException;
use App\Module\Books\Domain\Repository\BookRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RemoveBookHandler
{
    public function __construct(private BookRepositoryInterface $bookRepository)
    {
    }

    public function __invoke(RemoveBook $command): void
    {
        $book = $this->bookRepository->findById($command->id);

        if (null === $book) {
            throw new BookNotFoundException('Book not found.');
        }

        $this->bookRepository->remove($book);
    }
}
