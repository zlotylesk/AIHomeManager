<?php

declare(strict_types=1);

namespace App\Tests\Integration\Series;

use App\Module\Series\Application\Command\ImportRatingsFromTrakt;
use App\Module\Series\Application\Handler\ImportRatingsFromTraktHandler;
use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\Entity\Season;
use App\Module\Series\Domain\Entity\Series;
use App\Module\Series\Domain\Port\RatingsProviderInterface;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ImportRatingsFromTraktHandlerTest extends KernelTestCase
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

    public function testAppliesShowSeasonAndEpisodeRatingsToExistingSeries(): void
    {
        $this->seedWatchedSeries();

        ($this->handlerReturning([
            'shows' => [['traktId' => 1388, 'rating' => 9]],
            'seasons' => [['traktId' => 1388, 'seasonNumber' => 1, 'rating' => 8]],
            'episodes' => [['traktId' => 1388, 'seasonNumber' => 1, 'episodeNumber' => 1, 'rating' => 10]],
        ]))(new ImportRatingsFromTrakt());
        $this->em->clear();

        $series = $this->repository->findByTraktId('1388');
        self::assertNotNull($series);
        self::assertSame(9, $series->rating()?->value());

        $season = $this->seasonsByNumber($series)[1];
        self::assertSame(8, $season->rating()?->value());

        $episode = $this->episodeByNumber($season, 1);
        self::assertSame(10, $episode->rating()?->value());
    }

    public function testSkipsRatingsForMissingSeriesSeasonOrEpisode(): void
    {
        $this->seedWatchedSeries();

        ($this->handlerReturning([
            'shows' => [['traktId' => 9999, 'rating' => 9]],                                       // series not imported
            'seasons' => [['traktId' => 1388, 'seasonNumber' => 5, 'rating' => 8]],                // season absent
            'episodes' => [['traktId' => 1388, 'seasonNumber' => 1, 'episodeNumber' => 99, 'rating' => 10]], // episode absent
        ]))(new ImportRatingsFromTrakt());
        $this->em->clear();

        self::assertSame(1, $this->countRows('series')); // no phantom series materialised

        $series = $this->repository->findByTraktId('1388');
        self::assertNotNull($series);
        self::assertNull($series->rating());

        $season = $this->seasonsByNumber($series)[1];
        self::assertNull($season->rating());
        self::assertNull($this->episodeByNumber($season, 1)->rating());
    }

    public function testReimportOnUnchangedRatingsKeepsValuesStable(): void
    {
        $this->seedWatchedSeries();

        $ratings = [
            'shows' => [['traktId' => 1388, 'rating' => 7]],
            'seasons' => [],
            'episodes' => [['traktId' => 1388, 'seasonNumber' => 1, 'episodeNumber' => 1, 'rating' => 6]],
        ];

        ($this->handlerReturning($ratings))(new ImportRatingsFromTrakt());
        $this->em->clear();
        ($this->handlerReturning($ratings))(new ImportRatingsFromTrakt());
        $this->em->clear();

        $series = $this->repository->findByTraktId('1388');
        self::assertNotNull($series);
        self::assertSame(7, $series->rating()?->value());
        self::assertSame(6, $this->episodeByNumber($this->seasonsByNumber($series)[1], 1)->rating()?->value());
    }

    private function seedWatchedSeries(): void
    {
        $series = new Series('s-1', 'Breaking Bad');
        $series->linkTrakt('1388');
        $series->addSeason(new Season('se-1', 's-1', 1));
        $series->addEpisode('se-1', new Episode('ep-1', 'se-1', 'Pilot', 1));
        $this->repository->save($series);
        $this->em->clear();
    }

    /**
     * @param array{shows: list<array{traktId: int, rating: int}>, seasons: list<array{traktId: int, seasonNumber: int, rating: int}>, episodes: list<array{traktId: int, seasonNumber: int, episodeNumber: int, rating: int}>} $ratings
     */
    private function handlerReturning(array $ratings): ImportRatingsFromTraktHandler
    {
        $provider = $this->createStub(RatingsProviderInterface::class);
        $provider->method('fetchRatings')->willReturn($ratings);

        return new ImportRatingsFromTraktHandler($provider, $this->repository, new NullLogger());
    }

    /**
     * @return array<int, Season>
     */
    private function seasonsByNumber(Series $series): array
    {
        $byNumber = [];
        foreach ($series->seasons() as $season) {
            $byNumber[$season->number()] = $season;
        }

        return $byNumber;
    }

    private function episodeByNumber(Season $season, int $number): Episode
    {
        foreach ($season->episodes() as $episode) {
            if ($episode->number() === $number) {
                return $episode;
            }
        }

        self::fail(sprintf('Episode %d not found in season %d.', $number, $season->number()));
    }

    private function countRows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
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
}
