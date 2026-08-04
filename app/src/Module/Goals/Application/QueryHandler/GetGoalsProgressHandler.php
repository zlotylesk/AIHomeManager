<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\QueryHandler;

use App\Module\Goals\Application\DTO\GoalProgressDTO;
use App\Module\Goals\Application\Query\GetGoalsProgress;
use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Enum\Period;
use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use App\Module\Goals\Domain\Service\GoalProgressCalculator;
use App\Module\Goals\Domain\ValueObject\GoalTarget;
use App\Shared\Pagination\Page;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetGoalsProgressHandler
{
    public function __construct(
        private Connection $connection,
        private ActivityProviderInterface $activityProvider,
        private GoalProgressCalculator $calculator,
    ) {
    }

    /**
     * @return Page<GoalProgressDTO>
     */
    public function __invoke(GetGoalsProgress $query): Page
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM goals');

        $goals = $this->connection->fetchAllAssociative(
            'SELECT id, type, target_value, period FROM goals ORDER BY id LIMIT :limit OFFSET :offset',
            ['limit' => $query->page->perPage, 'offset' => $query->page->offset()],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        if ([] === $goals) {
            return Page::of([], $total, $query->page);
        }

        $now = new DateTimeImmutable();

        // Fetch the activity stream once, over the widest window any goal needs.
        $earliest = $now;
        foreach ($goals as $goal) {
            $start = $this->calculator->windowStartFor(Period::from((string) $goal['period']), $now);
            if ($start < $earliest) {
                $earliest = $start;
            }
        }
        $events = $this->activityProvider->activityBetween($earliest, $now);

        $result = [];
        foreach ($goals as $goal) {
            $type = GoalType::from((string) $goal['type']);
            $period = Period::from((string) $goal['period']);
            $progress = $this->calculator->progress(
                $type,
                new GoalTarget((int) $goal['target_value']),
                $period,
                $events,
                $now,
            );

            $result[] = new GoalProgressDTO(
                goalId: (string) $goal['id'],
                type: $type->value,
                period: $period->value,
                target: $progress->target,
                achieved: $progress->achieved,
                percent: $progress->percent,
                met: $progress->isMet,
            );
        }

        return Page::of($result, $total, $query->page);
    }
}
