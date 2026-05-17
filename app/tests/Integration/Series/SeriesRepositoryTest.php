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
        $episode = new Episode('ep-1', 'season-1', 'Pilot');

        $series->addSeason($season);
        $series->addEpisode('season-1', $episode);
        $series->rateEpisode('season-1', 'ep-1', new Rating(9));

        $this->repository->save($series);
        $this->em->clear();

        $found = $this->repository->findById('id-1');

        self::assertNotNull($found);
        self::assertCount(1, $found->seasons());

        $foundSeason = array_values($found->seasons())[0];
        self::assertSame(1, $foundSeason->number());
        self::assertCount(1, $foundSeason->episodes());

        $foundEpisode = array_values($foundSeason->episodes())[0];
        self::assertSame('Pilot', $foundEpisode->title());
        self::assertNotNull($foundEpisode->rating());
        self::assertSame(9, $foundEpisode->rating()->value());
    }

    public function testFindAllLoadsSeasonsAndEpisodes(): void
    {
        $first = new Series(id: 's-1', title: 'Breaking Bad');
        $first->addSeason(new Season(id: 's-1-se-1', seriesId: 's-1', number: 1));
        $first->addEpisode('s-1-se-1', new Episode('s-1-ep-1', 's-1-se-1', 'Pilot'));
        $first->addEpisode('s-1-se-1', new Episode('s-1-ep-2', 's-1-se-1', 'Cat in the Bag'));
        $first->addSeason(new Season(id: 's-1-se-2', seriesId: 's-1', number: 2));
        $first->addEpisode('s-1-se-2', new Episode('s-1-ep-3', 's-1-se-2', 'Seven Thirty-Seven'));
        $first->rateEpisode('s-1-se-1', 's-1-ep-1', new Rating(9));

        $second = new Series(id: 's-2', title: 'The Wire');
        $second->addSeason(new Season(id: 's-2-se-1', seriesId: 's-2', number: 1));
        $second->addEpisode('s-2-se-1', new Episode('s-2-ep-1', 's-2-se-1', 'The Target'));

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
}
