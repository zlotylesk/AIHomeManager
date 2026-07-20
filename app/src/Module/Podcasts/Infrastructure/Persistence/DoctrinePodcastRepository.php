<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Infrastructure\Persistence;

use App\Module\Podcasts\Domain\Entity\Podcast;
use App\Module\Podcasts\Domain\Repository\PodcastRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePodcastRepository implements PodcastRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Podcast $podcast): void
    {
        $this->entityManager->persist($podcast);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?Podcast
    {
        return $this->entityManager->find(Podcast::class, $id);
    }

    /** @return Podcast[] */
    public function findAll(): array
    {
        return $this->entityManager
            ->createQuery('SELECT p FROM '.Podcast::class.' p ORDER BY p.title.value ASC')
            ->getResult();
    }

    public function remove(Podcast $podcast): void
    {
        $this->entityManager->remove($podcast);
        $this->entityManager->flush();
    }
}
