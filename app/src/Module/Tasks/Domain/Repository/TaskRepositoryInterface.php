<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\Repository;

use App\Module\Tasks\Domain\Entity\Task;
use DateTimeImmutable;

interface TaskRepositoryInterface
{
    public function save(Task $task): void;

    public function findById(string $id): ?Task;

    /** @return Task[] */
    public function findAll(): array;

    /**
     * @return Task[]
     */
    public function findByDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array;
}
