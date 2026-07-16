<?php

declare(strict_types=1);

namespace App\Tests\Integration\Movies;

use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Enum\MovieStatus;
use App\Module\Movies\Domain\ValueObject\Rating;
use App\Module\Movies\Domain\ValueObject\Title;
use App\Module\Movies\Infrastructure\Persistence\DoctrineMovieRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MovieRepositoryTest extends KernelTestCase
{
    private DoctrineMovieRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineMovieRepository($this->em);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE movies');
    }

    public function testSaveAndFindByIdRoundTripsEmbeddedTitleAndCreatedAt(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-15 10:30:00');
        $this->repository->save(new Movie(
            'm0000001-0000-0000-0000-000000000001',
            new Title('Blade Runner 2049'),
            $createdAt,
        ));
        $this->em->clear();

        $found = $this->repository->findById('m0000001-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame('Blade Runner 2049', $found->title()->value());
        self::assertSame($createdAt->format('Y-m-d H:i:s'), $found->createdAt()->format('Y-m-d H:i:s'));
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repository->findById('00000000-0000-0000-0000-000000000000'));
    }

    public function testSavePersistsRenamedTitle(): void
    {
        $this->repository->save(new Movie(
            'm0000002-0000-0000-0000-000000000001',
            new Title('Dune'),
            new DateTimeImmutable(),
        ));
        $this->em->clear();

        $loaded = $this->repository->findById('m0000002-0000-0000-0000-000000000001');
        self::assertNotNull($loaded);
        $loaded->rename(new Title('Dune: Part Two'));
        $this->repository->save($loaded);
        $this->em->clear();

        $reloaded = $this->repository->findById('m0000002-0000-0000-0000-000000000001');
        self::assertNotNull($reloaded);
        self::assertSame('Dune: Part Two', $reloaded->title()->value());
    }

    public function testTitleSurvivesTheRoundTripAtFullColumnWidth(): void
    {
        $longTitle = str_repeat('ż', Title::MAX_LENGTH);
        $this->repository->save(new Movie(
            'm0000003-0000-0000-0000-000000000001',
            new Title($longTitle),
            new DateTimeImmutable(),
        ));
        $this->em->clear();

        $found = $this->repository->findById('m0000003-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame($longTitle, $found->title()->value());
    }

    public function testFindAllReturnsAllSavedMovies(): void
    {
        $this->repository->save(new Movie('m0000004-0000-0000-0000-000000000001', new Title('Arrival'), new DateTimeImmutable()));
        $this->repository->save(new Movie('m0000004-0000-0000-0000-000000000002', new Title('Sicario'), new DateTimeImmutable()));
        $this->em->clear();

        self::assertCount(2, $this->repository->findAll());
    }

    public function testRemoveDeletesMovie(): void
    {
        $this->repository->save(new Movie(
            'm0000005-0000-0000-0000-000000000001',
            new Title('Prisoners'),
            new DateTimeImmutable(),
        ));
        $this->em->clear();

        $loaded = $this->repository->findById('m0000005-0000-0000-0000-000000000001');
        self::assertNotNull($loaded);
        $this->repository->remove($loaded);
        $this->em->clear();

        self::assertNull($this->repository->findById('m0000005-0000-0000-0000-000000000001'));
    }

    public function testWatchedFlagTimeAndRatingRoundTrip(): void
    {
        $movie = new Movie('m0000006-0000-0000-0000-000000000001', new Title('Heat'), new DateTimeImmutable());
        $movie->markWatched(new DateTimeImmutable('2026-07-10 21:00:00'));
        $movie->rate(new Rating(9));
        $this->repository->save($movie);
        $this->em->clear();

        $found = $this->repository->findById('m0000006-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertTrue($found->isWatched());
        self::assertSame('2026-07-10 21:00:00', $found->watchedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(9, $found->userRating()?->value());
    }

    public function testUnwatchedUnratedMovieHydratesNullNotABrokenRating(): void
    {
        $this->repository->save(new Movie(
            'm0000007-0000-0000-0000-000000000001',
            new Title('Dune'),
            new DateTimeImmutable(),
        ));
        $this->em->clear();

        $found = $this->repository->findById('m0000007-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertFalse($found->isWatched());
        self::assertNull($found->watchedAt());
        // The custom movie_rating DBAL type must return a real null for a NULL column,
        // not a hydrated-but-broken Rating (the nullable-embeddable hazard).
        self::assertNull($found->userRating());
        // Same for the metadata: movie_status must round-trip NULL cleanly.
        self::assertNull($found->coverUrl());
        self::assertNull($found->year());
        self::assertNull($found->status());
        self::assertNull($found->description());
    }

    public function testMetadataRoundTrips(): void
    {
        $movie = new Movie('m0000008-0000-0000-0000-000000000001', new Title('Heat'), new DateTimeImmutable());
        $movie->updateMetadata('https://example.com/poster.jpg', 1995, MovieStatus::RELEASED, 'A heist film.');
        $this->repository->save($movie);
        $this->em->clear();

        $found = $this->repository->findById('m0000008-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame('https://example.com/poster.jpg', $found->coverUrl());
        self::assertSame(1995, $found->year());
        self::assertSame(MovieStatus::RELEASED, $found->status());
        self::assertSame('A heist film.', $found->description());
    }
}
