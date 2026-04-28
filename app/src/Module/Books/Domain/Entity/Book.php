<?php

declare(strict_types=1);

namespace App\Module\Books\Domain\Entity;

use App\Module\Books\Domain\Enum\BookStatus;
use App\Module\Books\Domain\ValueObject\ISBN;
use App\Module\Books\Domain\ValueObject\ReadingProgress;

final class Book
{
    private BookStatus $status;
    private ReadingProgress $readingProgress;

    /** @var ReadingSession[] */
    private array $sessions = [];

    /** @var object[] */
    private array $recordedEvents = [];

    public function __construct(
        private readonly string $id,
        private readonly ISBN $isbn,
        private readonly string $title,
        private readonly string $author,
        private readonly string $publisher,
        private readonly int $year,
        private readonly ?string $coverUrl,
        private readonly int $totalPages,
    ) {
        $this->status = BookStatus::TO_READ;
        $this->readingProgress = new ReadingProgress(0, $totalPages);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isbn(): ISBN
    {
        return $this->isbn;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function author(): string
    {
        return $this->author;
    }

    public function publisher(): string
    {
        return $this->publisher;
    }

    public function year(): int
    {
        return $this->year;
    }

    public function coverUrl(): ?string
    {
        return $this->coverUrl;
    }

    public function totalPages(): int
    {
        return $this->totalPages;
    }

    public function readingProgress(): ReadingProgress
    {
        return $this->readingProgress;
    }

    public function status(): BookStatus
    {
        return $this->status;
    }

    public function addReadingSession(ReadingSession $session): void
    {
        if ($this->status === BookStatus::TO_READ) {
            $this->status = BookStatus::READING;
        }

        $newCurrentPage = min(
            $this->readingProgress->currentPage() + $session->pagesRead(),
            $this->totalPages
        );

        $this->readingProgress = $this->readingProgress->withCurrentPage($newCurrentPage);

        if ($this->readingProgress->isCompleted()) {
            $this->status = BookStatus::COMPLETED;
        }

        $this->sessions[] = $session;
    }

    /** @return object[] */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
