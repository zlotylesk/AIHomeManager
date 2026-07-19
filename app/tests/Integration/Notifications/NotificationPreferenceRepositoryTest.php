<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\ValueObject\QuietHours;
use App\Module\Notifications\Infrastructure\Persistence\DoctrineNotificationPreferenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the mapping against a real database rather than trusting
 * schema:validate — the custom quiet_hours/notification_type types and the JSON
 * channel set only reveal a bad round-trip at read time.
 */
final class NotificationPreferenceRepositoryTest extends KernelTestCase
{
    private DoctrineNotificationPreferenceRepository $repository;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineNotificationPreferenceRepository($this->em);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE notification_preferences');
    }

    public function testRoundTripsTypeChannelsAndQuietHours(): void
    {
        $preference = new NotificationPreference(
            'p0000001-0000-0000-0000-000000000001',
            NotificationType::TASK_DUE,
            true,
            [Channel::EMAIL, Channel::PUSH],
            QuietHours::fromTimes('22:00', '07:00'),
        );
        $this->repository->save($preference);
        $this->em->clear();

        $found = $this->repository->findByType(NotificationType::TASK_DUE);

        self::assertNotNull($found);
        self::assertSame(NotificationType::TASK_DUE, $found->type());
        self::assertTrue($found->isEnabled());
        self::assertTrue($found->isChannelEnabled(Channel::EMAIL));
        self::assertTrue($found->isChannelEnabled(Channel::PUSH));
        self::assertNotNull($found->quietHours());
        self::assertSame('22:00', $found->quietHours()->start());
        self::assertSame('07:00', $found->quietHours()->end());
        self::assertTrue($found->quietHours()->isOvernight());
    }

    public function testHydratesARealNullForAnUnsetQuietHoursWindow(): void
    {
        $this->repository->save(new NotificationPreference(
            'p0000002-0000-0000-0000-000000000001',
            NotificationType::DAILY_DIGEST,
            false,
            [],
        ));
        $this->em->clear();

        $found = $this->repository->findByType(NotificationType::DAILY_DIGEST);

        self::assertNotNull($found);
        self::assertNull($found->quietHours(), 'a NULL column must not hydrate as a broken VO');
        self::assertFalse($found->isEnabled());
        self::assertSame([], $found->enabledChannels());
    }

    public function testFindByTypeReturnsNullForAnUnconfiguredType(): void
    {
        self::assertNull($this->repository->findByType(NotificationType::GOAL_STREAK_AT_RISK));
    }

    public function testSavePersistsChannelAndWindowChanges(): void
    {
        $this->repository->save(NotificationPreference::defaultFor(
            'p0000003-0000-0000-0000-000000000001',
            NotificationType::ARTICLE_DAILY,
        ));
        $this->em->clear();

        $loaded = $this->repository->findByType(NotificationType::ARTICLE_DAILY);
        self::assertNotNull($loaded);
        $loaded->disableChannel(Channel::PUSH);
        $loaded->setQuietHours(QuietHours::fromTimes('09:00', '17:00'));
        $this->repository->save($loaded);
        $this->em->clear();

        $reloaded = $this->repository->findByType(NotificationType::ARTICLE_DAILY);

        self::assertNotNull($reloaded);
        self::assertSame([Channel::EMAIL], $reloaded->enabledChannels());
        self::assertNotNull($reloaded->quietHours());
        self::assertFalse($reloaded->quietHours()->isOvernight());
        self::assertSame('09:00', $reloaded->quietHours()->start());
    }

    public function testFindAllReturnsEveryConfiguredType(): void
    {
        $this->repository->save(NotificationPreference::defaultFor('p0000004-0000-0000-0000-000000000001', NotificationType::TASK_DUE));
        $this->repository->save(NotificationPreference::defaultFor('p0000004-0000-0000-0000-000000000002', NotificationType::DAILY_DIGEST));
        $this->em->clear();

        self::assertCount(2, $this->repository->findAll());
    }
}
