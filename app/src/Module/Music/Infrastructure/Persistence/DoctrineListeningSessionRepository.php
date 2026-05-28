<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\Persistence;

use App\Module\Music\Domain\Entity\ListeningSession;
use App\Module\Music\Domain\Repository\ListeningSessionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineListeningSessionRepository implements ListeningSessionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(ListeningSession $session): void
    {
        $this->entityManager->persist($session);
        $this->entityManager->flush();
    }

    public function existsByDedupHash(string $dedupHash): bool
    {
        $found = $this->entityManager->getConnection()->fetchOne(
            'SELECT 1 FROM music_listening_sessions WHERE dedup_hash = :hash LIMIT 1',
            ['hash' => $dedupHash],
        );

        return false !== $found;
    }
}
