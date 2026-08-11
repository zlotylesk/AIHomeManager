<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * Remembers which alerts have already been announced, so an outage lasting a
 * week costs one e-mail rather than two thousand.
 *
 * Deliberately **not** a table and not a cache pool. This state is read and
 * written on the one code path whose job is to work while MySQL, Redis or
 * RabbitMQ are down; putting it in any of them would make the alert about a
 * dead dependency depend on that dependency.
 */
interface AlertStateStoreInterface
{
    /**
     * @return array<string, StoredAlert> keyed by the namespaced alert key
     */
    public function load(): array;

    /**
     * @param array<string, StoredAlert> $alerts
     */
    public function save(array $alerts): void;
}
