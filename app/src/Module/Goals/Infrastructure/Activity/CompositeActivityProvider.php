<?php

declare(strict_types=1);

namespace App\Module\Goals\Infrastructure\Activity;

use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use DateTimeImmutable;

/**
 * Fans an activity query out to every per-module adapter and concatenates the
 * results into a single normalized stream. Wired as the ActivityProviderInterface
 * implementation, receiving the tagged adapters via a tagged iterator.
 */
final readonly class CompositeActivityProvider implements ActivityProviderInterface
{
    /**
     * @param iterable<ActivityProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    public function activityBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $events = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->activityBetween($from, $to) as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
