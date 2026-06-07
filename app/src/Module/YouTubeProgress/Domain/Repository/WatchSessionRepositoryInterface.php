<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Domain\Repository;

use App\Module\YouTubeProgress\Domain\Entity\WatchSession;
use App\Module\YouTubeProgress\Domain\ValueObject\WatchSessionId;

interface WatchSessionRepositoryInterface
{
    public function save(WatchSession $session): void;

    public function findById(WatchSessionId $id): ?WatchSession;

    /** @return WatchSession[] */
    public function findAll(): array;

    public function deleteAll(): void;
}
