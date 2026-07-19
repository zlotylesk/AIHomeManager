<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Module\Notifications\Domain\Entity\PushSubscription;
use App\Module\Notifications\Infrastructure\Persistence\DoctrinePushSubscriptionRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PushSubscriptionRepositoryTest extends KernelTestCase
{
    private DoctrinePushSubscriptionRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrinePushSubscriptionRepository($this->em);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE push_subscriptions');
    }

    public function testRoundTripsASubscription(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-19 08:15:00');
        $this->repository->save($this->subscription('s-1', 'https://push.example.com/laptop', $createdAt));
        $this->em->clear();

        $found = $this->repository->findByEndpoint('https://push.example.com/laptop');

        self::assertNotNull($found);
        self::assertSame('s-1', $found->id());
        self::assertSame('p256dh-s-1', $found->publicKey());
        self::assertSame('auth-s-1', $found->authToken());
        self::assertSame($createdAt->format('Y-m-d H:i:s'), $found->createdAt()->format('Y-m-d H:i:s'));
    }

    public function testFindsEverySubscribedBrowser(): void
    {
        $this->repository->save($this->subscription('s-1', 'https://push.example.com/laptop'));
        $this->repository->save($this->subscription('s-2', 'https://push.example.com/phone'));
        $this->em->clear();

        self::assertCount(2, $this->repository->findAll());
    }

    public function testRemovesAnExpiredSubscription(): void
    {
        $this->repository->save($this->subscription('s-1', 'https://push.example.com/laptop'));
        $this->em->clear();

        $found = $this->repository->findByEndpoint('https://push.example.com/laptop');
        self::assertNotNull($found);
        $this->repository->remove($found);
        $this->em->clear();

        self::assertNull($this->repository->findByEndpoint('https://push.example.com/laptop'));
        self::assertSame([], $this->repository->findAll());
    }

    public function testAnUnknownEndpointResolvesToNull(): void
    {
        self::assertNull($this->repository->findByEndpoint('https://push.example.com/never'));
    }

    /**
     * Push services mint long endpoints; a column too short would only fail on a
     * real subscription.
     */
    public function testStoresALongEndpoint(): void
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.str_repeat('a', 400);
        $this->repository->save($this->subscription('s-long', $endpoint));
        $this->em->clear();

        self::assertNotNull($this->repository->findByEndpoint($endpoint));
    }

    private function subscription(string $id, string $endpoint, ?DateTimeImmutable $createdAt = null): PushSubscription
    {
        return new PushSubscription(
            $id,
            $endpoint,
            'p256dh-'.$id,
            'auth-'.$id,
            $createdAt ?? new DateTimeImmutable('2026-07-19 08:15:00'),
        );
    }
}
