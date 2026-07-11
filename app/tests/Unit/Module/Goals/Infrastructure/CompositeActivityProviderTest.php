<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Goals\Infrastructure;

use App\Module\Goals\Domain\Enum\GoalType;
use App\Module\Goals\Domain\Port\ActivityProviderInterface;
use App\Module\Goals\Domain\ReadModel\ActivityEvent;
use App\Module\Goals\Infrastructure\Activity\CompositeActivityProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CompositeActivityProviderTest extends TestCase
{
    public function testConcatenatesEventsFromAllProvidersInOrder(): void
    {
        $first = $this->providerReturning([
            new ActivityEvent(GoalType::BOOK_PAGES, 30, new DateTimeImmutable('2026-07-02')),
        ]);
        $second = $this->providerReturning([
            new ActivityEvent(GoalType::SERIES_EPISODES, 1, new DateTimeImmutable('2026-07-03')),
            new ActivityEvent(GoalType::ARTICLES_READ, 1, new DateTimeImmutable('2026-07-04')),
        ]);

        $composite = new CompositeActivityProvider([$first, $second]);

        $events = $composite->activityBetween(
            new DateTimeImmutable('2026-07-01'),
            new DateTimeImmutable('2026-07-31'),
        );

        self::assertCount(3, $events);
        self::assertSame(GoalType::BOOK_PAGES, $events[0]->type);
        self::assertSame(30, $events[0]->value);
        self::assertSame(GoalType::SERIES_EPISODES, $events[1]->type);
        self::assertSame(GoalType::ARTICLES_READ, $events[2]->type);
    }

    public function testReturnsEmptyWhenNoProviders(): void
    {
        $composite = new CompositeActivityProvider([]);

        self::assertSame(
            [],
            $composite->activityBetween(new DateTimeImmutable('2026-07-01'), new DateTimeImmutable('2026-07-31')),
        );
    }

    public function testPassesTheWindowThroughToEachProvider(): void
    {
        $from = new DateTimeImmutable('2026-07-10 00:00:00');
        $to = new DateTimeImmutable('2026-07-20 23:59:59');

        $provider = new class implements ActivityProviderInterface {
            /** @var array<int, DateTimeImmutable> */
            public array $captured = [];

            public function activityBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                $this->captured = [$from, $to];

                return [];
            }
        };

        new CompositeActivityProvider([$provider])->activityBetween($from, $to);

        self::assertSame([$from, $to], $provider->captured);
    }

    /**
     * @param ActivityEvent[] $events
     */
    private function providerReturning(array $events): ActivityProviderInterface
    {
        return new readonly class($events) implements ActivityProviderInterface {
            /** @param ActivityEvent[] $events */
            public function __construct(private array $events)
            {
            }

            public function activityBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
            {
                return $this->events;
            }
        };
    }
}
