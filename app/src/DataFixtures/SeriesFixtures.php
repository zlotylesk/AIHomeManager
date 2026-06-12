<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\Entity\Season;
use App\Module\Series\Domain\Entity\Series;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use App\Module\Series\Domain\ValueObject\Rating;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * HMAI-39: Seeds 3 series × 2 seasons × 5 episodes with ratings 6–9.
 *
 * Uses the domain repository (not the EntityManager directly) so the aggregate's
 * invariants stay enforced and recorded events surface naturally to the worker
 * — same path the production HTTP endpoints take. `--append` is not supported:
 * doctrine:fixtures:load truncates the tables before this purge.
 */
final class SeriesFixtures extends Fixture
{
    public function __construct(private readonly SeriesRepositoryInterface $repository)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $catalog = [
            ['id' => 'fixture-series-bb', 'title' => 'Breaking Bad', 'episodes' => ['Pilot', 'Cat in the Bag', 'And the Bag\'s in the River', 'Cancer Man', 'Gray Matter']],
            ['id' => 'fixture-series-wire', 'title' => 'The Wire', 'episodes' => ['The Target', 'The Detail', 'The Buys', 'Old Cases', 'The Pager']],
            ['id' => 'fixture-series-sopranos', 'title' => 'The Sopranos', 'episodes' => ['Pilot', '46 Long', 'Denial, Anger, Acceptance', 'Meadowlands', 'College']],
        ];

        $ratings = [6, 7, 8, 9, 8];

        foreach ($catalog as $entry) {
            $series = new Series(id: $entry['id'], title: $entry['title']);

            foreach ([1, 2] as $seasonNumber) {
                $seasonId = sprintf('%s-s%d', $entry['id'], $seasonNumber);
                $series->addSeason(new Season(id: $seasonId, seriesId: $entry['id'], number: $seasonNumber));

                foreach ($entry['episodes'] as $episodeIndex => $episodeTitle) {
                    $episodeId = sprintf('%s-e%d', $seasonId, $episodeIndex + 1);
                    $series->addEpisode($seasonId, new Episode($episodeId, $seasonId, $episodeTitle, $episodeIndex + 1));
                    $series->rateEpisode($seasonId, $episodeId, new Rating($ratings[$episodeIndex]));
                }
            }

            $this->repository->save($series);
        }
    }
}
