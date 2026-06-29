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
                'episode_id' => 'e1', 'episode_title' => 'Pilot', 'episode_number' => 1, 'episode_rating' => '8',
            ],
            [
                'series_id' => 's1', 'series_title' => 'Breaking Bad', 'series_created_at' => '2026-05-01',
                'season_id' => 'se1', 'season_number' => 1,
                'episode_id' => 'e2', 'episode_title' => 'Cat in the Bag', 'episode_number' => 2, 'episode_rating' => null,
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
        self::assertSame(1, $result[0]->seasons[0]->episodes[0]->number);
        self::assertSame(2, $result[0]->seasons[0]->episodes[1]->number);
        self::assertSame(8, $result[0]->seasons[0]->episodes[0]->rating);
        self::assertNull($result[0]->seasons[0]->episodes[1]->rating);
        self::assertSame([], $result[1]->seasons);
    }

    public function testHydratesOwnSeriesAndSeasonRatingsSeparatelyFromEpisodes(): void
    {
        $rows = [
            [
                'series_id' => 's1', 'series_title' => 'Breaking Bad', 'series_created_at' => '2026-05-01',
                'series_rating' => '10',
                'season_id' => 'se1', 'season_number' => 1, 'season_rating' => '7',
                'episode_id' => 'e1', 'episode_title' => 'Pilot', 'episode_number' => 1, 'episode_rating' => '8',
            ],
        ];

        $result = $this->hydrator->hydrate($rows);

        self::assertSame(10, $result[0]->rating);
        self::assertSame(7, $result[0]->seasons[0]->rating);
        self::assertSame(8, $result[0]->seasons[0]->episodes[0]->rating);
    }

    public function testOwnRatingsDefaultToNullWhenColumnsAreNull(): void
    {
        $rows = [
            [
                'series_id' => 's1', 'series_title' => 'Breaking Bad', 'series_created_at' => '2026-05-01',
                'series_rating' => null,
                'season_id' => 'se1', 'season_number' => 1, 'season_rating' => null,
                'episode_id' => 'e1', 'episode_title' => 'Pilot', 'episode_number' => 1, 'episode_rating' => null,
            ],
        ];

        $result = $this->hydrator->hydrate($rows);

        self::assertNull($result[0]->rating);
        self::assertNull($result[0]->seasons[0]->rating);
    }

    public function testComputesSeasonAndShowAverageRatingRoundedToTwoDecimals(): void
    {
        // (8 + 7 + 7) / 3 = 7.333... -> 7.33; an unrated episode is excluded.
        $rows = [
            $this->episodeRow('e1', 1, '8'),
            $this->episodeRow('e2', 2, '7'),
            $this->episodeRow('e3', 3, '7'),
            $this->episodeRow('e4', 4, null),
        ];

        $result = $this->hydrator->hydrate($rows);

        self::assertSame(7.33, $result[0]->seasons[0]->averageRating);
        self::assertSame(7.33, $result[0]->averageRating);
    }

    public function testShowAverageSpansAllSeasonsIndependentlyOfSeasonAverages(): void
    {
        $rows = [
            $this->episodeRow('e1', 1, '10', seasonId: 'se1', seasonNumber: 1),
            $this->episodeRow('e2', 1, '5', seasonId: 'se2', seasonNumber: 2),
            $this->episodeRow('e3', 2, '5', seasonId: 'se2', seasonNumber: 2),
        ];

        $result = $this->hydrator->hydrate($rows);

        self::assertSame(10.0, $result[0]->seasons[0]->averageRating);
        self::assertSame(5.0, $result[0]->seasons[1]->averageRating);
        // (10 + 5 + 5) / 3 = 6.666... -> 6.67
        self::assertSame(6.67, $result[0]->averageRating);
    }

    public function testAverageRatingIsNullWhenNoEpisodesAreRated(): void
    {
        $rows = [
            $this->episodeRow('e1', 1, null),
            $this->episodeRow('e2', 2, null),
        ];

        $result = $this->hydrator->hydrate($rows);

        self::assertNull($result[0]->seasons[0]->averageRating);
        self::assertNull($result[0]->averageRating);
    }

    public function testCountsWatchedAndTotalEpisodesPerSeasonAndShow(): void
    {
        $rows = [
            $this->episodeRow('e1', 1, '8', seasonId: 'se1', seasonNumber: 1, watched: 1),
            $this->episodeRow('e2', 2, '6', seasonId: 'se1', seasonNumber: 1, watched: 0),
            $this->episodeRow('e3', 1, '7', seasonId: 'se2', seasonNumber: 2, watched: 1),
        ];

        $result = $this->hydrator->hydrate($rows);

        self::assertSame(1, $result[0]->seasons[0]->watchedCount);
        self::assertSame(2, $result[0]->seasons[0]->episodeCount);
        self::assertSame(1, $result[0]->seasons[1]->watchedCount);
        self::assertSame(1, $result[0]->seasons[1]->episodeCount);
        self::assertSame(2, $result[0]->watchedCount);
        self::assertSame(3, $result[0]->episodeCount);
    }

    public function testCountersAndAverageAreZeroAndNullForSeriesWithoutEpisodes(): void
    {
        $rows = [
            [
                'series_id' => 's1', 'series_title' => 'Empty Show', 'series_created_at' => '2026-05-01',
                'season_id' => null, 'season_number' => null,
                'episode_id' => null, 'episode_title' => null, 'episode_rating' => null,
            ],
        ];

        $result = $this->hydrator->hydrate($rows);

        self::assertNull($result[0]->averageRating);
        self::assertSame(0, $result[0]->watchedCount);
        self::assertSame(0, $result[0]->episodeCount);
    }

    /**
     * Builds one JOIN row for series `s1` with a single season by default.
     *
     * @return array<string, mixed>
     */
    private function episodeRow(
        string $episodeId,
        int $episodeNumber,
        ?string $rating,
        string $seasonId = 'se1',
        int $seasonNumber = 1,
        int $watched = 0,
    ): array {
        return [
            'series_id' => 's1', 'series_title' => 'Breaking Bad', 'series_created_at' => '2026-05-01',
            'season_id' => $seasonId, 'season_number' => $seasonNumber,
            'episode_id' => $episodeId, 'episode_title' => 'Ep '.$episodeNumber, 'episode_number' => $episodeNumber,
            'episode_rating' => $rating, 'episode_watched' => $watched,
        ];
    }
}
