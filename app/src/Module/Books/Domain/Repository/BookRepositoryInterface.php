<?php

declare(strict_types=1);

namespace App\Module\Books\Domain\Repository;

use App\Module\Books\Domain\Entity\Book;
use App\Module\Books\Domain\Enum\BookStatus;

interface BookRepositoryInterface
{
    public function save(Book $book): void;

    public function findById(string $id): ?Book;

    /** @return Book[] */
    public function findAll(): array;

    /** @return Book[] */
    public function findByStatus(BookStatus $status): array;
}
