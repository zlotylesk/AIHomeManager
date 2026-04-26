<?php

declare(strict_types=1);

namespace App\Module\Articles\Domain\Repository;

use App\Module\Articles\Domain\Entity\ArticleDailyPick;

interface ArticleDailyPickRepositoryInterface
{
    public function save(ArticleDailyPick $pick): void;

    /** @return string[] */
    public function findRecentlyPickedIds(int $days): array;
}
