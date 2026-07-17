<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\Repository;

use App\Module\Notifications\Domain\Entity\Notification;

interface NotificationRepositoryInterface
{
    public function save(Notification $notification): void;

    public function findById(string $id): ?Notification;

    /**
     * The notification already recorded for the given occurrence, if any. The
     * lookup backing idempotency: a trigger that finds one must not create a
     * second notification for the same occurrence.
     */
    public function findByDedupKey(string $dedupKey): ?Notification;
}
