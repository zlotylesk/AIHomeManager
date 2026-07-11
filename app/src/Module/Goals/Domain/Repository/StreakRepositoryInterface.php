<?php

declare(strict_types=1);

namespace App\Module\Goals\Domain\Repository;

use App\Module\Goals\Domain\Entity\Streak;
use App\Module\Goals\Domain\Enum\GoalType;

interface StreakRepositoryInterface
{
    public function save(Streak $streak): void;

    public function findById(string $id): ?Streak;

    public function findByType(GoalType $type): ?Streak;

    /** @return Streak[] */
    public function findAll(): array;
}
