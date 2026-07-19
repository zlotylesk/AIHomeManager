<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Persistence;

use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineNotificationPreferenceRepository implements NotificationPreferenceRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(NotificationPreference $preference): void
    {
        $this->entityManager->persist($preference);
        $this->entityManager->flush();
    }

    public function findByType(NotificationType $type): ?NotificationPreference
    {
        return $this->entityManager->createQuery(
            'SELECT p FROM '.NotificationPreference::class.' p WHERE p.type = :type'
        )
            ->setParameter('type', $type->value)
            ->getOneOrNullResult();
    }

    /** @return NotificationPreference[] */
    public function findAll(): array
    {
        return $this->entityManager->createQuery('SELECT p FROM '.NotificationPreference::class.' p')->getResult();
    }
}
