<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Provider;

use App\Module\Notifications\Domain\Port\NotificationCandidateProviderInterface;
use DateTimeImmutable;

/**
 * Presents every registered source as one provider, so the sweep handler stays
 * unaware of how many there are. New sources arrive by tag alone.
 */
final readonly class CompositeNotificationCandidateProvider implements NotificationCandidateProviderInterface
{
    /**
     * @param iterable<NotificationCandidateProviderInterface> $providers
     */
    public function __construct(private iterable $providers)
    {
    }

    public function candidatesAt(DateTimeImmutable $at): array
    {
        $candidates = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->candidatesAt($at) as $candidate) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }
}
