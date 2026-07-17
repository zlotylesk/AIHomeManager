<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\Repository;

use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\NotificationType;

interface NotificationPreferenceRepositoryInterface
{
    public function save(NotificationPreference $preference): void;

    /**
     * The stored preference for the type, or null when the user has never
     * configured it — the caller decides what the unconfigured default is.
     */
    public function findByType(NotificationType $type): ?NotificationPreference;

    /** @return NotificationPreference[] */
    public function findAll(): array;
}
