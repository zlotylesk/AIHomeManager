<?php

declare(strict_types=1);

namespace App\Module\Books\Domain\Event;

use DateTimeImmutable;

final readonly class BookCompleted
{
    public DateTimeImmutable $occurredAt;

    public function __construct(public string $bookId)
    {
        $this->occurredAt = new DateTimeImmutable();
    }
}
