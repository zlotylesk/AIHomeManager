<?php

declare(strict_types=1);

namespace App\Tests\Support\Monitoring;

use App\Monitoring\AlertStateStoreInterface;
use App\Monitoring\StoredAlert;

/**
 * The announced-alert set held in a property, so a test can drive several
 * sweeps in a row without touching the filesystem. The file-backed
 * implementation is pinned separately by its own test.
 */
final class InMemoryAlertStateStore implements AlertStateStoreInterface
{
    /** @var array<string, StoredAlert> */
    private array $alerts = [];

    public function load(): array
    {
        return $this->alerts;
    }

    public function save(array $alerts): void
    {
        $this->alerts = $alerts;
    }
}
