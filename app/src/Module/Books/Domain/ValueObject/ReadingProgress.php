<?php

declare(strict_types=1);

namespace App\Module\Books\Domain\ValueObject;

use InvalidArgumentException;

final readonly class ReadingProgress
{
    public function __construct(
        private int $currentPage,
        private int $totalPages,
    ) {
        if ($totalPages <= 0) {
            throw new InvalidArgumentException('Total pages must be a positive number.');
        }

        if ($currentPage < 0) {
            throw new InvalidArgumentException('Current page cannot be negative.');
        }

        if ($currentPage > $totalPages) {
            throw new InvalidArgumentException(sprintf('Current page (%d) cannot exceed total pages (%d).', $currentPage, $totalPages));
        }
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function totalPages(): int
    {
        return $this->totalPages;
    }

    public function percentage(): float
    {
        return round($this->currentPage / $this->totalPages * 100, 1);
    }

    public function isCompleted(): bool
    {
        return $this->currentPage >= $this->totalPages;
    }

    public function withCurrentPage(int $page): self
    {
        return new self($page, $this->totalPages);
    }
}
