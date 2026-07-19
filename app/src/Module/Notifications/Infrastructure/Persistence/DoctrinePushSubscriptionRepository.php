<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Persistence;

use App\Module\Notifications\Domain\Entity\PushSubscription;
use App\Module\Notifications\Domain\Repository\PushSubscriptionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePushSubscriptionRepository implements PushSubscriptionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(PushSubscription $subscription): void
    {
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
    }

    public function findAll(): array
    {
        /** @var list<PushSubscription> $subscriptions */
        $subscriptions = $this->entityManager->createQuery(
            'SELECT s FROM '.PushSubscription::class.' s ORDER BY s.createdAt ASC'
        )->getResult();

        return $subscriptions;
    }

    public function findByEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->entityManager->createQuery(
            'SELECT s FROM '.PushSubscription::class.' s WHERE s.endpoint = :endpoint'
        )
            ->setParameter('endpoint', $endpoint)
            ->getOneOrNullResult();
    }

    public function remove(PushSubscription $subscription): void
    {
        $this->entityManager->remove($subscription);
        $this->entityManager->flush();
    }
}
