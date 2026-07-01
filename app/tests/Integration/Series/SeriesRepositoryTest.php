<?php

declare(strict_types=1);

namespace App\Tests\Integration\Series;

use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\Entity\Season;
use App\Module\Series\Domain\Entity\Series;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use App\Module\Series\Domain\ValueObject\Rating;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SeriesRepositoryTest extends KernelTestCase
{
    private SeriesRepositoryInterface $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(SeriesRepositoryInterface::class);
        $this->truncateTables();
    }

    private function truncateTables(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('TRUNCATE TABLE series_episodes');
        $conn->executeStatement('TRUNCATE TABLE series_seasons');
        $conn->executeStatement('TRUNCATE TABLE series');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testSaveAndFindById(): void
    {
        $series = new Series(id: 'id-1', title: 'Breaking Bad');
        $this->repository->save($series);
        $this->em->clear();

        $found = $this->repository->findById('id-1');

        self::assertNotNull($found);
        self::assertSame('id-1', $found->id());
        self::assertSame('Breaking Bad', $found->title());
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->findById('non-existent'));
    }

    public function testFindAllReturnsAllSeries(): void
    {
        $this->repository->save(new Series(id: 'id-1', title: 'Breaking Bad'));
        $this->repository->save(new Series(id: 'id-2', title: 'The Wire'));
        $this->em->clear();

        $all = $this->repository->findAll();

        self::assertCount(2, $all);
    }

    public function testFindAllReturnsEmptyArrayWhenNoSeries(): void
    {
        self::assertSame([], $this->repository->findAll());
    }

    public function testSaveAndLoadSeriesWithSeasonsAndEpisodes(): void
    {
        $series = new Series(id: 'id-1', title: 'Breaking Bad');
        $season = new Season(id: 'season-1', seriesId: 'id-1', number: 1);
        $episode = new Episode('ep-1', 'season-1', 'Pilot', 1);

        $series->addSeason($season);
        $series->addEpisode('season-1', $episode);
        $series->rateEpisode('season-1', 'ep-1', new Rating(9));

        $this->repository->save($series);
        $this->em->clear();

        $found = $this->repository->findById('id-1');

        self::assertNotNull($found);
        self::assertCount(1, $found->seasons());

        $foundSeason = array_first($found->seasons());
        self::assertSame(1, $foundSeason->number());
        self::assertCount(1, $foundSeason->episodes());

        $foundEpisode = array_first($foundSeason->episodes());
        self::assertSame('Pilot', $foundEpisode->title());
        self::assertNotNull($foundEpisode->rating());
        self::assertSame(9, $foundEpisode->rating()->value());
    }

    public function testSaveAndFindByTraktId(): void
    {
        $series = new Series(id: 'id-1', title: 'Breaking Bad');
        $series->linkTrakt('1388');
        $this->repository->save($series);
        $this->em->clear();

        $found = $this->repository->findByTraktId('1388');

        self::assertNotNull($found);
        self::assertSame('id-1', $found->id());
        self::assertSame('1388', $found->traktId());
    }

    public function testFindByTraktIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->findByTraktId('does-not-exist'));
    }

    public function testFindByTraktIdNeverMatchesManuallyAddedSeries(): void
    {
        $this->repository->save(new Series(id: 'id-1', title: 'Breaking Bad'));
        $this->em->clear();

        self::assertNull($this->repository->findByTraktId('1388'));
    }

    public function testFindAllLoadsSeasonsAndEpisodes(): void
    {
        $first = new Series(id: 's-1', title: 'Breaking Bad');
        $first->addSeason(new Season(id: 's-1-se-1', seriesId: 's-1', number: 1));
        $first->addEpisode('s-1-se-1', new Episode('s-1-ep-1', 's-1-se-1', 'Pilot', 1));
        $first->addEpisode('s-1-se-1', new Episode('s-1-ep-2', 's-1-se-1', 'Cat in the Bag', 2));
        $first->addSeason(new Season(id: 's-1-se-2', seriesId: 's-1', number: 2));
        $first->addEpisode('s-1-se-2', new Episode('s-1-ep-3', 's-1-se-2', 'Seven Thirty-Seven', 1));
        $first->rateEpisode('s-1-se-1', 's-1-ep-1', new Rating(9));

        $second = new Series(id: 's-2', title: 'The Wire');
        $second->addSeason(new Season(id: 's-2-se-1', seriesId: 's-2', number: 1));
        $second->addEpisode('s-2-se-1', new Episode('s-2-ep-1', 's-2-se-1', 'The Target', 1));

        $this->repository->save($first);
        $this->repository->save($second);
        $this->em->clear();

        /** @var array<string, Series> $all */
        $all = [];
        foreach ($this->repository->findAll() as $loaded) {
            $all[$loaded->id()] = $loaded;
        }

        self::assertCount(2, $all);
        self::assertCount(2, $all['s-1']->seasons());
        self::assertCount(1, $all['s-2']->seasons());

        $seasonsById = [];
        foreach ($all['s-1']->seasons() as $season) {
            $seasonsById[$season->id()] = $season;
        }
        self::assertCount(2, $seasonsById['s-1-se-1']->episodes());
        self::assertCount(1, $seasonsById['s-1-se-2']->episodes());

        $firstEpisode = $seasonsById['s-1-se-1']->findEpisode('s-1-ep-1');
        self::assertNotNull($firstEpisode);
        self::assertNotNull($firstEpisode->rating());
        self::assertSame(9, $firstEpisode->rating()->value());
    }

    /**
     * The aggregate has no ORM associations (entities persisted by string FK),
     * so orphanRemoval/cascade cannot fire — the cascade is spelled out by hand
     * in the repository (ADR-007). These three tests pin that hand-written
     * cascade against raw row counts, so a future delete path that forgets to
     * remove children fails loudly instead of leaking orphaned rows.
     */
    public function testDeleteCascadesToSeasonsAndEpisodes(): void
    {
        $this->repository->save($this->breakingBadWithTwoSeasons());
        $this->em->clear();

        $found = $this->repository->findById('id-1');
        self::assertNotNull($found);

        $this->repository->delete($found);
        $this->em->clear();

        self::assertNull($this->repository->findById('id-1'));
        self::assertSame(0, $this->countRows('series'));
        self::assertSame(0, $this->countRows('series_seasons'));
        self::assertSame(0, $this->countRows('series_episodes'));
    }

    public function testDeleteSeasonCascadesToItsEpisodesOnly(): void
    {
        $this->repository->save($this->breakingBadWithTwoSeasons());
        $this->em->clear();

        $found = $this->repository->findById('id-1');
        self::assertNotNull($found);

        $this->repository->deleteSeason($found->seasons()['se-1']);
        $this->em->clear();

        // The series and the sibling season (with its single episode) survive.
        self::assertSame(1, $this->countRows('series'));
        self::assertSame(1, $this->countRows('series_seasons'));
        self::assertSame(1, $this->countRows('series_episodes'));

        $reloaded = $this->repository->findById('id-1');
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->seasons());
        self::assertArrayHasKey('se-2', $reloaded->seasons());
    }

    public function testDeleteEpisodeRemovesOnlyThatEpisode(): void
    {
        $this->repository->save($this->breakingBadWithTwoSeasons());
        $this->em->clear();

        $found = $this->repository->findById('id-1');
        self::assertNotNull($found);

        $episode = $found->seasons()['se-1']->findEpisode('ep-1');
        self::assertNotNull($episode);

        $this->repository->deleteEpisode($episode);
        $this->em->clear();

        // Only the one episode row is gone; series, both seasons, siblings stay.
        self::assertSame(1, $this->countRows('series'));
        self::assertSame(2, $this->countRows('series_seasons'));
        self::assertSame(2, $this->countRows('series_episodes'));

        $reloaded = $this->repository->findById('id-1');
        self::assertNotNull($reloaded);
        $season = $reloaded->seasons()['se-1'];
        self::assertNull($season->findEpisode('ep-1'));
        self::assertNotNull($season->findEpisode('ep-2'));
    }

    private function breakingBadWithTwoSeasons(): Series
    {
        $series = new Series(id: 'id-1', title: 'Breaking Bad');
        $series->addSeason(new Season(id: 'se-1', seriesId: 'id-1', number: 1));
        $series->addEpisode('se-1', new Episode('ep-1', 'se-1', 'Pilot', 1));
        $series->addEpisode('se-1', new Episode('ep-2', 'se-1', 'Cat in the Bag', 2));
        $series->addSeason(new Season(id: 'se-2', seriesId: 'id-1', number: 2));
        $series->addEpisode('se-2', new Episode('ep-3', 'se-2', 'Seven Thirty-Seven', 1));

        return $series;
    }

    private function countRows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
