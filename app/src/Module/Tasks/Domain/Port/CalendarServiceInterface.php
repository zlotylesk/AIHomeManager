<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\Port;

use App\Module\Tasks\Domain\Entity\Task;

interface CalendarServiceInterface
{
    public function createEvent(Task $task): string;

    public function updateEvent(Task $task): void;

    public function deleteEvent(string $googleEventId): void;
}