<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Application\Service;

use App\Module\Series\Application\Service\SeriesRowHydrator;
use PHPUnit\Framework\TestCase;

final class SeriesRowHydratorTest extends TestCase
{
    private SeriesRowHydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new SeriesRowHydrator();
    }

    public function testEmptyResultSetProducesEmptyList(): void
    {
        self::assertSame([], $this->hydrator->hydrate([]));
    }

    public function testSeriesWithoutSeasonsFromLeftJoinNulls(): void
    {
        $rows = [
            [
                'series_id' => 's1', 'series_title' => 'Breaking Bad', 'series_created_at' => '2026-05-01',
                'season_id' => null, 'season_number' => null,
                'episode_id' => null, 'episode_title' => null, 'episode_rating' => null,
            ],
        ];

        $result = $this->hydrator->hydrate($rows);

        self::assertCount(1, $result);
        self::assertSame('s1', $result[0]->id);
        self::assertSame('Breaking Bad', $result[0]->title);
        self::assertSame([], $result[0]->seasons);
    }

    public function testHydratesSeasonsAndEpisodesGroupedBySeries(): void
    {
        $rows = [
            [
                'series_id' => 's1', 'series_title' => 'Breaking Bad', 'series_created_at' => '2026-05-01',
                'season_id' => 'se1', 'season_number' => 1,
                'episode_id' => 'e1', 'episode_title' => 'Pilot', 'episode_rating' => '8',
            ],
            [
                'series_id' => 's1', 'series_title' => 'Breaking Bad', 'series_created_at' => '2026-05-01',
                'season_id' => 'se1', 'season_number' => 1,
                'episode_id' => 'e2', 'episode_title' => 'Cat in the Bag', 'episode_rating' => null,
            ],
            [
                'series_id' => 's2', 'series_title' => 'Better Call Saul', 'series_created_at' => '2026-05-02',
                'season_id' => null, 'season_number' => null,
                'episode_id' => null, 'episode_title' => null, 'episode_rating' => null,
            ],
        ];

        $result = $this->hydrator->hydrate($rows);

        self::assertCount(2, $result);
        self::assertCount(1, $result[0]->seasons);
        self::assertSame(1, $result[0]->seasons[0]->number);
        self::assertCount(2, $result[0]->seasons[0]->episodes);
        self::assertSame(8, $result[0]->seasons[0]->episodes[0]->rating);
        self::assertNull($result[0]->seasons[0]->episodes[1]->rating);
        self::assertSame([], $result[1]->seasons);
    }
}
