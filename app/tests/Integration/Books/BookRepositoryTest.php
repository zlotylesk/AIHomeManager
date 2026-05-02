<?php

declare(strict_types=1);

namespace App\Tests\Integration\Books;

use App\Module\Books\Domain\Entity\Book;
use App\Module\Books\Domain\Enum\BookStatus;
use App\Module\Books\Domain\ValueObject\ISBN;
use App\Module\Books\Infrastructure\Persistence\DoctrineBookRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class BookRepositoryTest extends KernelTestCase
{
    private DoctrineBookRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineBookRepository($this->em);

        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('TRUNCATE TABLE book_reading_sessions');
        $conn->executeStatement('TRUNCATE TABLE books');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function makeBook(string $id, string $isbn = '9780306406157', int $totalPages = 300): Book
    {
        return new Book(
            id: $id,
            isbn: new ISBN($isbn),
            title: 'Clean Code',
            author: 'Robert C. Martin',
            publisher: 'Prentice Hall',
            year: 2008,
            coverUrl: null,
            totalPages: $totalPages,
        );
    }

    public function testSaveAndFindById(): void
    {
        $book = $this->makeBook('c0000001-0000-0000-0000-000000000001');
        $this->repository->save($book);
        $this->em->clear();

        $found = $this->repository->findById('c0000001-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame('c0000001-0000-0000-0000-000000000001', $found->id());
        self::assertSame('Clean Code', $found->title());
        self::assertSame(BookStatus::TO_READ, $found->status());
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->findById('00000000-0000-0000-0000-000000000000'));
    }

    public function testFindAllReturnsAllSavedBooks(): void
    {
        $this->repository->save($this->makeBook('c0000002-0000-0000-0000-000000000001', '9780306406157'));
        $this->repository->save($this->makeBook('c0000002-0000-0000-0000-000000000002', '080442957X'));
        $this->em->clear();

        self::assertCount(2, $this->repository->findAll());
    }

    public function testFindByStatusReturnsOnlyMatchingBooks(): void
    {
        $reading = $this->makeBook('c0000003-0000-0000-0000-000000000001', '9780306406157', 100);
        $this->repository->save($reading);
        $this->em->clear();

        $loaded = $this->repository->findById('c0000003-0000-0000-0000-000000000001');
        $loaded->addReadingSession(new \App\Module\Books\Domain\Entity\ReadingSession(
            id: 'sess-0001-0000-0000-000000000001',
            bookId: $loaded->id(),
            date: new DateTimeImmutable(),
            pagesRead: 50,
        ));
        $this->repository->save($loaded);
        $this->em->clear();

        $toRead = $this->makeBook('c0000003-0000-0000-0000-000000000002', '080442957X');
        $this->repository->save($toRead);
        $this->em->clear();

        $readingBooks = $this->repository->findByStatus(BookStatus::READING);
        self::assertCount(1, $readingBooks);
        self::assertSame('c0000003-0000-0000-0000-000000000001', $readingBooks[0]->id());

        $toReadBooks = $this->repository->findByStatus(BookStatus::TO_READ);
        self::assertCount(1, $toReadBooks);

        $completedBooks = $this->repository->findByStatus(BookStatus::COMPLETED);
        self::assertCount(0, $completedBooks);
    }

    public function testRemoveDeletesBook(): void
    {
        $book = $this->makeBook('c0000004-0000-0000-0000-000000000001');
        $this->repository->save($book);
        $this->em->clear();

        $loaded = $this->repository->findById('c0000004-0000-0000-0000-000000000001');
        $this->repository->remove($loaded);
        $this->em->clear();

        self::assertNull($this->repository->findById('c0000004-0000-0000-0000-000000000001'));
    }
}
