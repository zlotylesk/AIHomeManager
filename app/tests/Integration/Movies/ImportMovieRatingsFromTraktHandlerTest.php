<?php

declare(strict_types=1);

namespace App\Tests\Integration\Movies;

use App\Module\Movies\Application\Command\ImportMovieRatingsFromTrakt;
use App\Module\Movies\Application\CommandHandler\ImportMovieRatingsFromTraktHandler;
use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Port\MovieRatingsProviderInterface;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use App\Module\Movies\Domain\ValueObject\Rating;
use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ImportMovieRatingsFromTraktHandlerTest extends KernelTestCase
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

    public function testAppliesRatingToImportedMovie(): void
    {
        $this->saveMovie('m-1', '6');

        ($this->handlerReturning([['traktId' => 6, 'rating' => 9]]))(new ImportMovieRatingsFromTrakt());
        $this->em->clear();

        $movie = $this->repository->findByTraktId('6');
        self::assertNotNull($movie);
        self::assertSame(9, $movie->userRating()?->value());
    }

    public function testSkipsRatingForMovieNeverImported(): void
    {
        ($this->handlerReturning([['traktId' => 999, 'rating' => 8]]))(new ImportMovieRatingsFromTrakt());
        $this->em->clear();

        self::assertSame(0, $this->countRows('movies'));
    }

    public function testIdempotentWhenRatingUnchanged(): void
    {
        $movie = $this->saveMovie('m-2', '6');
        $movie->rate(new Rating(9));
        $this->repository->save($movie);
        $this->em->clear();

        ($this->handlerReturning([['traktId' => 6, 'rating' => 9]]))(new ImportMovieRatingsFromTrakt());
        $this->em->clear();

        $reloaded = $this->repository->findByTraktId('6');
        self::assertNotNull($reloaded);
        self::assertSame(9, $reloaded->userRating()?->value());
        self::assertSame(1, $this->countRows('movies'));
    }

    private function saveMovie(string $id, string $traktId): Movie
    {
        $movie = new Movie($id, new Title('Heat'), new DateTimeImmutable());
        $movie->linkTrakt($traktId);
        $this->repository->save($movie);

        return $movie;
    }

    /**
     * @param list<array{traktId: int, rating: int}> $ratings
     */
    private function handlerReturning(array $ratings): ImportMovieRatingsFromTraktHandler
    {
        $provider = $this->createStub(MovieRatingsProviderInterface::class);
        $provider->method('fetchMovieRatings')->willReturn($ratings);

        return new ImportMovieRatingsFromTraktHandler($provider, $this->repository);
    }

    private function countRows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
