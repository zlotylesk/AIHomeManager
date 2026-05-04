<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Handler;

use App\Module\Books\Application\Command\AddBook;
use App\Module\Books\Domain\Entity\Book;
use App\Module\Books\Domain\Port\BookMetadataProviderInterface;
use App\Module\Books\Domain\Repository\BookRepositoryInterface;
use App\Module\Books\Domain\ValueObject\ISBN;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class AddBookHandler
{
    public function __construct(
        private BookRepositoryInterface $bookRepository,
        private BookMetadataProviderInterface $metadataProvider,
    ) {
    }

    public function __invoke(AddBook $command): string
    {
        $title = $command->title;
        $author = $command->author;
        $publisher = $command->publisher;
        $year = $command->year;
        $coverUrl = $command->coverUrl?->value();
        $totalPages = $command->totalPages;

        if (null === $title) {
            $metadata = $this->metadataProvider->getByIsbn($command->isbn);
            $title = $metadata->title;
            $author ??= $metadata->author;
            $publisher ??= $metadata->publisher;
            $year ??= $metadata->year;
            $coverUrl ??= $metadata->coverUrl;
            $totalPages ??= $metadata->totalPages;
        }

        if (null === $totalPages || $totalPages <= 0) {
            throw new InvalidArgumentException('Field "total_pages" is required and could not be retrieved from the National Library API.');
        }

        $book = new Book(
            id: Uuid::v4()->toRfc4122(),
            isbn: new ISBN($command->isbn),
            title: $title ?? '',
            author: $author ?? '',
            publisher: $publisher ?? '',
            year: $year ?? 0,
            coverUrl: $coverUrl,
            totalPages: $totalPages,
        );

        $this->bookRepository->save($book);

        return $book->id();
    }
}
