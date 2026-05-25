<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\Event;

use DateTimeImmutable;

final readonly class TaskCompleted
{
    public DateTimeImmutable $occurredAt;

    public function __construct(public string $taskId)
    {
        $this->occurredAt = new DateTimeImmutable();
    }
}
