<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\QueryHandler;

use App\Module\Goals\Application\DTO\StreakDTO;
use App\Module\Goals\Application\Query\GetStreaks;
use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use App\Module\Goals\Domain\Service\GoalProgressCalculator;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetStreaksHandler
{
    /** How far back the streak history is read (bounds the activity query). */
    private const int LOOKBACK_DAYS = 365;

    public function __construct(
        private Connection $connection,
        private ActivityProviderInterface $activityProvider,
        private GoalProgressCalculator $calculator,
    ) {
    }

    /**
     * @return StreakDTO[]
     */
    public function __invoke(GetStreaks $query): array
    {
        $types = $this->connection->fetchFirstColumn('SELECT DISTINCT type FROM goals ORDER BY type');

        if ([] === $types) {
            return [];
        }

        $now = new DateTimeImmutable();
        $from = $now->modify('-'.self::LOOKBACK_DAYS.' days')->setTime(0, 0);
        $events = $this->activityProvider->activityBetween($from, $now);

        $result = [];
        foreach ($types as $type) {
            $goalType = GoalType::from((string) $type);
            $streak = $this->calculator->streak($goalType, $events, $now);

            $result[] = new StreakDTO(
                type: $goalType->value,
                currentLength: $streak->currentLength,
                longestLength: $streak->longestLength,
                lastActivityDate: $streak->lastActivityDate?->format('Y-m-d'),
            );
        }

        return $result;
    }
}
