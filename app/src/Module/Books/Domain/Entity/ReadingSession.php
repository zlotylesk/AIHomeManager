<?php

declare(strict_types=1);

namespace App\Module\Books\Domain\Entity;

use DateTimeImmutable;

final readonly class ReadingSession
{
    public function __construct(
        private string $id,
        private string $bookId,
        private DateTimeImmutable $date,
        private int $pagesRead,
        private ?string $notes = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function bookId(): string
    {
        return $this->bookId;
    }

    public function date(): DateTimeImmutable
    {
        return $this->date;
    }

    public function pagesRead(): int
    {
        return $this->pagesRead;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }
}
