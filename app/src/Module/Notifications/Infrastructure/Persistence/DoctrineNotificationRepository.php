<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Persistence;

use App\Module\Notifications\Domain\Entity\Notification;
use App\Module\Notifications\Domain\Repository\NotificationRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineNotificationRepository implements NotificationRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Notification $notification): void
    {
        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?Notification
    {
        return $this->entityManager->find(Notification::class, $id);
    }

    public function findByDedupKey(string $dedupKey): ?Notification
    {
        return $this->entityManager->createQuery(
            'SELECT n FROM '.Notification::class.' n WHERE n.dedupKey = :dedupKey'
        )
            ->setParameter('dedupKey', $dedupKey)
            ->getOneOrNullResult();
    }
}
