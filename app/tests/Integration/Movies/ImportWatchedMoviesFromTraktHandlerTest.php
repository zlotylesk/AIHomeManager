<?php

declare(strict_types=1);

namespace App\Tests\Integration\Movies;

use App\Module\Movies\Application\Command\ImportMovieRatingsFromTrakt;
use App\Module\Movies\Application\Command\ImportWatchedMoviesFromTrakt;
use App\Module\Movies\Application\CommandHandler\ImportWatchedMoviesFromTraktHandler;
use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Port\WatchedMoviesProviderInterface;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class ImportWatchedMoviesFromTraktHandlerTest extends KernelTestCase
{
    private MovieRepositoryInterface $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repository = $container->get(MovieRepositoryInterface::class);
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE movies');
    }

    public function testFreshImportCreatesWatchedMovie(): void
    {
        ($this->handlerReturning($this->sampleMovies()))(new ImportWatchedMoviesFromTrakt());
        $this->em->clear();

        $movie = $this->repository->findByTraktId('6');
        self::assertNotNull($movie);
        self::assertSame('Blade Runner 2049', $movie->title()->value());
        self::assertSame('6', $movie->traktId());
        self::assertSame(2017, $movie->year());
        self::assertTrue($movie->isWatched());
        self::assertNotNull($movie->watchedAt());
        self::assertSame('2026-01-02', $movie->watchedAt()->format('Y-m-d'));
    }

    public function testReimportOnUnchangedDataCreatesNoDuplicates(): void
    {
        ($this->handlerReturning($this->sampleMovies()))(new ImportWatchedMoviesFromTrakt());
        $this->em->clear();

        ($this->handlerReturning($this->sampleMovies()))(new ImportWatchedMoviesFromTrakt());
        $this->em->clear();

        self::assertSame(1, $this->countRows('movies'));
    }

    public function testImportMatchesExistingMovieByTraktIdAndFlipsWatched(): void
    {
        $existing = new Movie('m-existing', new Title('Heat'), new DateTimeImmutable());
        $existing->linkTrakt('6');
        $this->repository->save($existing);
        $this->em->clear();

        ($this->handlerReturning([[
            'traktId' => 6,
            'title' => 'Blade Runner 2049',
            'year' => 2017,
            'lastWatchedAt' => '2026-02-01T18:00:00.000Z',
        ]]))(new ImportWatchedMoviesFromTrakt());
        $this->em->clear();

        self::assertSame(1, $this->countRows('movies'));

        $reloaded = $this->repository->findByTraktId('6');
        self::assertNotNull($reloaded);
        self::assertSame('m-existing', $reloaded->id());
        self::assertTrue($reloaded->isWatched());
        // The import flips watched but does not rename an existing movie.
        self::assertSame('Heat', $reloaded->title()->value());
    }

    public function testMissingTraktTokenPropagatesReadableException(): void
    {
        $provider = $this->createStub(WatchedMoviesProviderInterface::class);
        $provider->method('fetchWatchedMovies')->willThrowException(new RuntimeException('Trakt account not connected.'));
        $handler = new ImportWatchedMoviesFromTraktHandler($provider, $this->repository, $this->busStub());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Trakt account not connected.');

        $handler(new ImportWatchedMoviesFromTrakt());
    }

    public function testChainsRatingsImportAfterWatchedMovies(): void
    {
        $provider = $this->createStub(WatchedMoviesProviderInterface::class);
        $provider->method('fetchWatchedMovies')->willReturn($this->sampleMovies());

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(ImportMovieRatingsFromTrakt::class))
            ->willReturn(new Envelope(new ImportMovieRatingsFromTrakt()));

        (new ImportWatchedMoviesFromTraktHandler($provider, $this->repository, $bus))(new ImportWatchedMoviesFromTrakt());
    }

    /**
     * @param list<array{traktId: int, title: string, year: int|null, lastWatchedAt: string|null}> $movies
     */
    private function handlerReturning(array $movies): ImportWatchedMoviesFromTraktHandler
    {
        $provider = $this->createStub(WatchedMoviesProviderInterface::class);
        $provider->method('fetchWatchedMovies')->willReturn($movies);

        return new ImportWatchedMoviesFromTraktHandler($provider, $this->repository, $this->busStub());
    }

    private function busStub(): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope(new ImportMovieRatingsFromTrakt()));

        return $bus;
    }

    /**
     * @return list<array{traktId: int, title: string, year: int|null, lastWatchedAt: string|null}>
     */
    private function sampleMovies(): array
    {
        return [[
            'traktId' => 6,
            'title' => 'Blade Runner 2049',
            'year' => 2017,
            'lastWatchedAt' => '2026-01-02T20:00:00.000Z',
        ]];
    }

    private function countRows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
