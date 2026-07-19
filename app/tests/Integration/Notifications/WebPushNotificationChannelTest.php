<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Module\Notifications\Domain\Entity\Notification;
use App\Module\Notifications\Domain\Entity\PushSubscription;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Infrastructure\Channel\PushDeliveryResult;
use App\Module\Notifications\Infrastructure\Channel\WebPushNotificationChannel;
use App\Module\Notifications\Infrastructure\Channel\WebPushSenderInterface;
use App\Tests\Integration\Notifications\Support\InMemoryPushSubscriptions;
use App\Tests\Integration\Notifications\Support\RecordingPushSender;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class WebPushNotificationChannelTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = static::getContainer()->get(Environment::class);
    }

    public function testDeliversThePushChannel(): void
    {
        self::assertSame(Channel::PUSH, $this->channel(new InMemoryPushSubscriptions([]))->channel());
    }

    public function testFansOutToEverySubscribedBrowser(): void
    {
        $sender = new RecordingPushSender();
        $subscriptions = new InMemoryPushSubscriptions([
            $this->subscription('laptop'),
            $this->subscription('phone'),
        ]);

        $this->channel($subscriptions, $sender)->send($this->notification());

        self::assertCount(2, $sender->sent, 'a single user is still reachable on several devices');
    }

    public function testRendersTitleAndBodyFromThePerTypeTemplate(): void
    {
        $sender = new RecordingPushSender();

        $this->channel(new InMemoryPushSubscriptions([$this->subscription('laptop')]), $sender)
            ->send($this->notification(NotificationType::TASK_DUE, [
                'title' => 'Zapłacić czynsz',
                'dueAt' => '2026-07-16 18:00',
                'url' => 'https://aihm.local/tasks/42',
            ]));

        $payload = json_decode($sender->sent[0], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('Zbliża się termin', $payload['title']);
        self::assertStringContainsString('Zapłacić czynsz', (string) $payload['body']);
        self::assertSame('https://aihm.local/tasks/42', $payload['url']);
    }

    /**
     * The Service Worker opens this URL on click, and a payload URL can originate
     * from user-entered data (an imported article's own address).
     */
    public function testOnlyHttpUrlsReachTheServiceWorker(): void
    {
        $sender = new RecordingPushSender();

        $this->channel(new InMemoryPushSubscriptions([$this->subscription('laptop')]), $sender)
            ->send($this->notification(NotificationType::ARTICLE_DAILY, [
                'title' => 'Podejrzany artykuł',
                'url' => 'javascript:alert(1)',
            ]));

        $payload = json_decode($sender->sent[0], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('url', $payload);
    }

    public function testEveryNotificationTypeRendersATitleAndBody(): void
    {
        $sender = new RecordingPushSender();
        $channel = $this->channel(new InMemoryPushSubscriptions([$this->subscription('laptop')]), $sender);

        foreach (NotificationType::cases() as $type) {
            $channel->send($this->notification($type, ['title' => 'Tytuł']));
        }

        self::assertCount(\count(NotificationType::cases()), $sender->sent);

        foreach ($sender->sent as $encoded) {
            $payload = json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertNotSame('', trim((string) $payload['title']));
            self::assertNotSame('', trim((string) $payload['body']));
        }
    }

    public function testAnExpiredSubscriptionIsRemoved(): void
    {
        $dead = $this->subscription('dead');
        $alive = $this->subscription('alive');
        $subscriptions = new InMemoryPushSubscriptions([$dead, $alive]);
        $sender = new RecordingPushSender([
            'dead' => PushDeliveryResult::expired('410 Gone'),
        ]);

        $this->channel($subscriptions, $sender)->send($this->notification());

        self::assertSame([$alive], $subscriptions->findAll(), 'a 410 endpoint must not be retried forever');
    }

    public function testATransientFailureLeavesTheSubscriptionAlone(): void
    {
        $flaky = $this->subscription('flaky');
        $alive = $this->subscription('alive');
        $subscriptions = new InMemoryPushSubscriptions([$flaky, $alive]);
        $sender = new RecordingPushSender([
            'flaky' => PushDeliveryResult::failed('503 Service Unavailable'),
        ]);

        $this->channel($subscriptions, $sender)->send($this->notification());

        self::assertCount(2, $subscriptions->findAll(), 'a transient error does not mean the browser is gone');
    }

    public function testOneReachableDeviceCountsAsDelivered(): void
    {
        $subscriptions = new InMemoryPushSubscriptions([$this->subscription('dead'), $this->subscription('alive')]);
        $sender = new RecordingPushSender(['dead' => PushDeliveryResult::expired('410 Gone')]);

        // No exception thrown: the occurrence reached the user on the one device
        // that still works, so the engine records SENT rather than FAILED.
        $this->channel($subscriptions, $sender)->send($this->notification());

        self::assertCount(1, $sender->sent);
    }

    public function testACompleteMissIsReportedByThrowing(): void
    {
        $subscriptions = new InMemoryPushSubscriptions([$this->subscription('dead')]);
        $sender = new RecordingPushSender(['dead' => PushDeliveryResult::failed('503 Service Unavailable')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No push subscription accepted/');

        $this->channel($subscriptions, $sender)->send($this->notification());
    }

    public function testNoSubscriptionsIsReportedByThrowing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No push subscriptions are registered/');

        $this->channel(new InMemoryPushSubscriptions([]))->send($this->notification());
    }

    private function channel(
        InMemoryPushSubscriptions $subscriptions,
        ?WebPushSenderInterface $sender = null,
    ): WebPushNotificationChannel {
        return new WebPushNotificationChannel(
            $subscriptions,
            $sender ?? new RecordingPushSender(),
            $this->twig,
        );
    }

    private function subscription(string $name): PushSubscription
    {
        return new PushSubscription(
            $name,
            sprintf('https://push.example.com/%s', $name),
            'p256dh-'.$name,
            'auth-'.$name,
            new DateTimeImmutable('2026-07-19 08:15:00'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function notification(
        NotificationType $type = NotificationType::TASK_DUE,
        array $payload = ['title' => 'Zapłacić czynsz'],
    ): Notification {
        return new Notification(
            'n0000010-0000-0000-0000-000000000001',
            $type,
            Channel::PUSH,
            $payload,
            sprintf('%s:subject:2026-07-19:push', $type->value),
            new DateTimeImmutable('2026-07-19 08:15:00'),
        );
    }
}
