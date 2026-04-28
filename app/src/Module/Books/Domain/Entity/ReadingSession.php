<?php

declare(strict_types=1);

namespace App\Module\Books\Domain\Entity;

final class ReadingSession
{
    public function __construct(
        private readonly string $id,
        private readonly string $bookId,
        private readonly \DateTimeImmutable $date,
        private readonly int $pagesRead,
        private readonly ?string $notes = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function bookId(): string
    {
        return $this->bookId;
    }

    public function date(): \DateTimeImmutable
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
