<?php

declare(strict_types=1);

namespace App\Module\Music\Domain\Repository;

use App\Module\Music\Domain\Entity\ListeningSession;

interface ListeningSessionRepositoryInterface
{
    public function save(ListeningSession $session): void;

    public function existsByDedupHash(string $dedupHash): bool;
}
