<?php

declare(strict_types=1);

namespace App\Module\Movies\Infrastructure\Persistence;

use App\Module\Movies\Domain\Entity\Movie;
use App\Module\Movies\Domain\Repository\MovieRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineMovieRepository implements MovieRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Movie $movie): void
    {
        $this->entityManager->persist($movie);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?Movie
    {
        return $this->entityManager->find(Movie::class, $id);
    }

    /** @return Movie[] */
    public function findAll(): array
    {
        return $this->entityManager->createQuery('SELECT m FROM '.Movie::class.' m')->getResult();
    }

    public function remove(Movie $movie): void
    {
        $this->entityManager->remove($movie);
        $this->entityManager->flush();
    }
}
