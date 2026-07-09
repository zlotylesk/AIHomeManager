<?php

declare(strict_types=1);

namespace App\Module\Goals\Domain\Repository;

use App\Module\Goals\Domain\Entity\Goal;

interface GoalRepositoryInterface
{
    public function save(Goal $goal): void;

    public function findById(string $id): ?Goal;

    /** @return Goal[] */
    public function findAll(): array;

    public function remove(Goal $goal): void;
}
