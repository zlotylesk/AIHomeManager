<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\CommandHandler;

use App\Module\Goals\Application\Command\RecalculateStreaks;
use App\Module\Goals\Domain\Entity\Streak;
use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use App\Module\Goals\Domain\Repository\StreakRepositoryInterface;
use App\Module\Goals\Domain\Service\GoalProgressCalculator;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Reactively (nightly / on demand) folds the product-wide activity stream into
 * a persisted {@see Streak} per activity type via the {@see GoalProgressCalculator}.
 *
 * Deptrac-clean: activity is pulled through the {@see ActivityProviderInterface}
 * port (DBAL adapters), never by importing a source module's Domain/Persistence.
 * Idempotent: the recompute is deterministic and upserts one streak per type,
 * preserving the all-time longest run even when it falls outside the read window.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class RecalculateStreaksHandler
{
    /** How far back the streak history is read (bounds the activity query). */
    private const int LOOKBACK_DAYS = 365;

    public function __construct(
        private Connection $connection,
        private ActivityProviderInterface $activityProvider,
        private GoalProgressCalculator $calculator,
        private StreakRepositoryInterface $streaks,
    ) {
    }

    public function __invoke(RecalculateStreaks $command): void
    {
        $types = $this->connection->fetchFirstColumn('SELECT DISTINCT type FROM goals ORDER BY type');

        if ([] === $types) {
            return;
        }

        $now = new DateTimeImmutable();
        $from = $now->modify('-'.self::LOOKBACK_DAYS.' days')->setTime(0, 0);
        $events = $this->activityProvider->activityBetween($from, $now);

        foreach ($types as $type) {
            $goalType = GoalType::from((string) $type);
            $state = $this->calculator->streak($goalType, $events, $now);

            $streak = $this->streaks->findByType($goalType);
            if (null === $streak) {
                $this->streaks->save(new Streak(
                    Uuid::v4()->toRfc4122(),
                    $goalType,
                    $state->currentLength,
                    $state->longestLength,
                    $state->lastActivityDate,
                ));

                continue;
            }

            $streak->reconcile(
                $state->currentLength,
                max($streak->longestLength(), $state->longestLength),
                $state->lastActivityDate ?? $streak->lastActivityDate(),
            );
            $this->streaks->save($streak);
        }
    }
}
