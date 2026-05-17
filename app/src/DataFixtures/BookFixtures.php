<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Module\Books\Domain\Entity\Book;
use App\Module\Books\Domain\Entity\ReadingSession;
use App\Module\Books\Domain\Repository\BookRepositoryInterface;
use App\Module\Books\Domain\ValueObject\ISBN;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * HMAI-39: Seeds 5 books across every BookStatus (to_read, reading×2, completed×2)
 * with different reading progress amounts.
 *
 * Status is driven by Book::addReadingSession — we don't poke the enum directly.
 * `completed` requires the cumulative pages-read to equal totalPages exactly, so
 * the seed mirrors how the production flow drives the aggregate.
 */
final class BookFixtures extends Fixture
{
    public function __construct(private readonly BookRepositoryInterface $repository)
    {
    }

    public function load(ObjectManager $manager): void
    {
        // [id, isbn-13, title, author, publisher, year, totalPages, pagesAlreadyRead]
        $seeds = [
            ['fixture-book-1', '9780451524935', '1984', 'George Orwell', 'Signet Classic', 1950, 328, 0],
            ['fixture-book-2', '9780061120084', 'To Kill a Mockingbird', 'Harper Lee', 'Harper Perennial', 2006, 281, 120],
            ['fixture-book-3', '9780743273565', 'The Great Gatsby', 'F. Scott Fitzgerald', 'Scribner', 2004, 180, 60],
            ['fixture-book-4', '9780316769488', 'The Catcher in the Rye', 'J. D. Salinger', 'Little, Brown', 2001, 224, 224],
            ['fixture-book-5', '9780141439518', 'Pride and Prejudice', 'Jane Austen', 'Penguin Classics', 2003, 432, 432],
        ];

        foreach ($seeds as [$id, $isbn, $title, $author, $publisher, $year, $totalPages, $alreadyRead]) {
            $book = new Book(
                id: $id,
                isbn: new ISBN($isbn),
                title: $title,
                author: $author,
                publisher: $publisher,
                year: $year,
                coverUrl: null,
                totalPages: $totalPages,
            );

            if ($alreadyRead > 0) {
                $book->addReadingSession(new ReadingSession(
                    id: sprintf('%s-session-1', $id),
                    bookId: $id,
                    date: new DateTimeImmutable('-7 days'),
                    pagesRead: $alreadyRead,
                    notes: 'Fixture-seeded progress.',
                ));
            }

            $this->repository->save($book);
        }
    }
}
