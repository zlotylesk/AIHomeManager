<?php

declare(strict_types=1);

namespace App\Tests\Integration\Series;

use App\Module\Series\Application\Command\ImportRatingsFromTrakt;
use App\Module\Series\Application\Command\ImportWatchedShowsFromTrakt;
use App\Module\Series\Application\Handler\ImportWatchedShowsFromTraktHandler;
use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\Entity\Season;
use App\Module\Series\Domain\Entity\Series;
use App\Module\Series\Domain\Port\WatchedShowsProviderInterface;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class ImportWatchedShowsFromTraktHandlerTest extends KernelTestCase
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

    public function testFreshImportCreatesSeriesSeasonsAndWatchedEpisodes(): void
    {
        ($this->handlerReturning($this->sampleShows()))(new ImportWatchedShowsFromTrakt());
        $this->em->clear();

        $series = $this->repository->findByTraktId('1388');
        self::assertNotNull($series);
        self::assertSame('Breaking Bad', $series->title());
        self::assertSame('1388', $series->traktId());
        self::assertCount(2, $series->seasons());

        $seasons = $this->seasonsByNumber($series);
        self::assertCount(2, $seasons[1]->episodes());
        self::assertCount(1, $seasons[2]->episodes());

        foreach ($seasons[1]->episodes() as $episode) {
            self::assertTrue($episode->isWatched());
            self::assertNotNull($episode->watchedAt());
        }

        $firstEpisode = $this->episodeByNumber($seasons[1], 1);
        self::assertNotNull($firstEpisode->watchedAt());
        self::assertSame('2026-01-02', $firstEpisode->watchedAt()->format('Y-m-d'));
    }

    public function testReimportOnUnchangedDataCreatesNoDuplicates(): void
    {
        ($this->handlerReturning($this->sampleShows()))(new ImportWatchedShowsFromTrakt());
        $this->em->clear();

        ($this->handlerReturning($this->sampleShows()))(new ImportWatchedShowsFromTrakt());
        $this->em->clear();

        self::assertSame(1, $this->countRows('series'));
        self::assertSame(2, $this->countRows('series_seasons'));
        self::assertSame(3, $this->countRows('series_episodes'));
    }

    public function testImportMatchesExistingSeriesByTraktIdAndFlipsWatched(): void
    {
        $existing = new Series('s-existing', 'Breaking Bad');
        $existing->linkTrakt('1388');
        $existing->addSeason(new Season('se-1', 's-existing', 1));
        $existing->addEpisode('se-1', new Episode('ep-1', 'se-1', 'Pilot', 1));
        $this->repository->save($existing);
        $this->em->clear();

        ($this->handlerReturning([[
            'traktId' => 1388,
            'title' => 'Breaking Bad',
            'year' => 2008,
            'seasons' => [['number' => 1, 'episodes' => [['number' => 1, 'lastWatchedAt' => '2026-02-01T18:00:00.000Z']]]],
        ]]))(new ImportWatchedShowsFromTrakt());
        $this->em->clear();

        self::assertSame(1, $this->countRows('series'));
        self::assertSame(1, $this->countRows('series_episodes'));

        $reloaded = $this->repository->findByTraktId('1388');
        self::assertNotNull($reloaded);
        self::assertSame('s-existing', $reloaded->id());

        $episode = $this->episodeByNumber($this->seasonsByNumber($reloaded)[1], 1);
        self::assertTrue($episode->isWatched());
        self::assertSame('Pilot', $episode->title());
    }

    public function testShowWithNoWatchedEpisodesIsSkipped(): void
    {
        ($this->handlerReturning([[
            'traktId' => 9999,
            'title' => 'Never Started',
            'year' => 2020,
            'seasons' => [['number' => 1, 'episodes' => []]],
        ]]))(new ImportWatchedShowsFromTrakt());

        self::assertSame(0, $this->countRows('series'));
    }

    public function testMissingTraktTokenPropagatesReadableException(): void
    {
        $provider = $this->createStub(WatchedShowsProviderInterface::class);
        $provider->method('fetchWatchedShows')->willThrowException(new RuntimeException('Trakt account not connected.'));
        $handler = new ImportWatchedShowsFromTraktHandler($provider, $this->repository, new NullLogger(), $this->busStub());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trakt account not connected.');

        $handler(new ImportWatchedShowsFromTrakt());
    }

    public function testChainsRatingsImportAfterWatchedShows(): void
    {
        $provider = $this->createStub(WatchedShowsProviderInterface::class);
        $provider->method('fetchWatchedShows')->willReturn($this->sampleShows());

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(ImportRatingsFromTrakt::class))
            ->willReturn(new Envelope(new ImportRatingsFromTrakt()));

        (new ImportWatchedShowsFromTraktHandler($provider, $this->repository, new NullLogger(), $bus))(new ImportWatchedShowsFromTrakt());
    }

    /**
     * @param list<array{traktId: int, title: string, year: int|null, seasons: list<array{number: int, episodes: list<array{number: int, lastWatchedAt: string|null}>}>}> $shows
     */
    private function handlerReturning(array $shows): ImportWatchedShowsFromTraktHandler
    {
        $provider = $this->createStub(WatchedShowsProviderInterface::class);
        $provider->method('fetchWatchedShows')->willReturn($shows);

        return new ImportWatchedShowsFromTraktHandler($provider, $this->repository, new NullLogger(), $this->busStub());
    }

    private function busStub(): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope(new ImportRatingsFromTrakt()));

        return $bus;
    }

    /**
     * @return list<array{traktId: int, title: string, year: int|null, seasons: list<array{number: int, episodes: list<array{number: int, lastWatchedAt: string|null}>}>}>
     */
    private function sampleShows(): array
    {
        return [[
            'traktId' => 1388,
            'title' => 'Breaking Bad',
            'year' => 2008,
            'seasons' => [
                ['number' => 1, 'episodes' => [
                    ['number' => 1, 'lastWatchedAt' => '2026-01-02T20:00:00.000Z'],
                    ['number' => 2, 'lastWatchedAt' => '2026-01-03T20:00:00.000Z'],
                ]],
                ['number' => 2, 'episodes' => [
                    ['number' => 1, 'lastWatchedAt' => '2026-01-10T20:00:00.000Z'],
                ]],
            ],
        ]];
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
