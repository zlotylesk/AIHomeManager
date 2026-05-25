<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\Event;

use DateTimeImmutable;

final readonly class TaskDeleted
{
    public DateTimeImmutable $occurredAt;

    public function __construct(
        public string $taskId,
        public ?string $googleEventId,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }
}
