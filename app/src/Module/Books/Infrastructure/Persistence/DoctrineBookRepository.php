<?php

declare(strict_types=1);

namespace App\Module\Books\Infrastructure\Persistence;

use App\Module\Books\Domain\Entity\Book;
use App\Module\Books\Domain\Enum\BookStatus;
use App\Module\Books\Domain\Repository\BookRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineBookRepository implements BookRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function save(Book $book): void
    {
        $this->entityManager->persist($book);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?Book
    {
        return $this->entityManager->find(Book::class, $id);
    }

    /** @return Book[] */
    public function findAll(): array
    {
        return $this->entityManager->createQuery('SELECT b FROM ' . Book::class . ' b')->getResult();
    }

    /** @return Book[] */
    public function findByStatus(BookStatus $status): array
    {
        return $this->entityManager->createQuery(
            'SELECT b FROM ' . Book::class . ' b WHERE b.status = :status'
        )
            ->setParameter('status', $status->value)
            ->getResult();
    }

    public function remove(Book $book): void
    {
        $this->entityManager->remove($book);
        $this->entityManager->flush();
    }
}
