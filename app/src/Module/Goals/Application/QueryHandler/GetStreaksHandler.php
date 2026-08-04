<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\QueryHandler;

use App\Module\Goals\Application\DTO\StreakDTO;
use App\Module\Goals\Application\Query\GetStreaks;
use App\Shared\Activity\StreakReaderInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Serves `/api/goals/streaks` from the shared {@see StreakReaderInterface}, the
 * same source the cockpit reads (HMAI-412) — this handler used to compute its
 * own answer, which is how the two screens came to show different numbers for
 * the same activity.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetStreaksHandler
{
    public function __construct(private StreakReaderInterface $streaks)
    {
    }

    /**
     * @return StreakDTO[]
     */
    public function __invoke(GetStreaks $query): array
    {
        return array_values(array_map(
            static fn ($streak): StreakDTO => new StreakDTO(
                type: $streak->type,
                currentLength: $streak->currentLength,
                longestLength: $streak->longestLength,
                lastActivityDate: $streak->lastActivityDate?->format('Y-m-d'),
            ),
            $this->streaks->streaksByType(),
        ));
    }
}
