<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Infrastructure\Persistence;

use App\Module\Podcasts\Domain\Entity\PodcastListeningSession;
use App\Module\Podcasts\Domain\Repository\PodcastListeningSessionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePodcastListeningSessionRepository implements PodcastListeningSessionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(PodcastListeningSession $session): void
    {
        $this->entityManager->persist($session);
        $this->entityManager->flush();
    }

    public function findByDedupHash(string $dedupHash): ?PodcastListeningSession
    {
        return $this->entityManager
            ->createQuery(
                'SELECT s FROM '.PodcastListeningSession::class.' s WHERE s.dedupHash = :hash'
            )
            ->setParameter('hash', $dedupHash)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }
}
