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
}
