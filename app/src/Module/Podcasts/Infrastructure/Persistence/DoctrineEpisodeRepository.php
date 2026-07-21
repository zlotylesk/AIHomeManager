<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Infrastructure\Persistence;

use App\Module\Podcasts\Domain\Entity\Episode;
use App\Module\Podcasts\Domain\Repository\EpisodeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEpisodeRepository implements EpisodeRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Episode $episode): void
    {
        $this->entityManager->persist($episode);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?Episode
    {
        return $this->entityManager->find(Episode::class, $id);
    }

    public function findByExternalId(string $externalId): ?Episode
    {
        return $this->entityManager
            ->createQuery('SELECT e FROM '.Episode::class.' e WHERE e.externalId = :externalId')
            ->setParameter('externalId', $externalId)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /**
     * Newest episode first — the order a listener reads a show in, and the order
     * the detail view will render. Episodes with no publication date sort last
     * rather than jumping to the top on a NULL.
     *
     * @return Episode[]
     */
    public function findByPodcastId(string $podcastId): array
    {
        return $this->entityManager
            ->createQuery(
                'SELECT e FROM '.Episode::class.' e
                 WHERE e.podcastId = :podcastId
                 ORDER BY e.publishedAt DESC, e.createdAt DESC'
            )
            ->setParameter('podcastId', $podcastId)
            ->getResult();
    }

    public function remove(Episode $episode): void
    {
        $this->entityManager->remove($episode);
        $this->entityManager->flush();
    }
}
