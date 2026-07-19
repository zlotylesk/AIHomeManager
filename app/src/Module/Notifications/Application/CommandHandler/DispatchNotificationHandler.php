<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\CommandHandler;

use App\Module\Notifications\Application\Command\DispatchNotification;
use App\Module\Notifications\Domain\Entity\Notification;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationStatus;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\Port\NotificationChannelInterface;
use App\Module\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Module\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Module\Notifications\Domain\Service\DispatchPolicy;
use App\Module\Notifications\Domain\ValueObject\DedupKey;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Orchestrates one occurrence into actual deliveries: asks {@see DispatchPolicy}
 * which channels should carry it, skips what was already announced, and hands
 * each notification to its channel adapter.
 *
 * The decision rules stay in the Domain policy; this layer owns only the things
 * the Domain must not touch — id generation, the clock, and the adapters.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class DispatchNotificationHandler
{
    /**
     * @param iterable<NotificationChannelInterface> $channelAdapters
     */
    public function __construct(
        private NotificationPreferenceRepositoryInterface $preferences,
        private NotificationRepositoryInterface $notifications,
        private DispatchPolicy $policy,
        private iterable $channelAdapters,
    ) {
    }

    public function __invoke(DispatchNotification $command): void
    {
        $type = NotificationType::tryFrom($command->type)
            ?? throw new InvalidArgumentException(sprintf('Unknown notification type "%s".', $command->type));

        $dedupKey = DedupKey::forOccurrence($type, $command->subject, $command->window);
        $at = new DateTimeImmutable();
        $channels = $this->policy->resolveChannels($type, $this->preferences->findByType($type), $at);

        foreach ($channels as $channel) {
            $adapter = $this->adapterFor($channel);

            // No adapter installed for this channel yet: record nothing, so the
            // occurrence can still be announced once one is.
            if (null === $adapter) {
                continue;
            }

            $notification = $this->pendingNotificationFor($type, $channel, $dedupKey, $command->payload, $at);

            if (null === $notification) {
                continue;
            }

            $this->deliver($notification, $adapter);
        }
    }

    /**
     * The notification to attempt delivery on, or null when this occurrence was
     * already delivered over this channel. A previous attempt that failed (or
     * never ran) is retried on its existing aggregate rather than duplicated.
     *
     * @param array<string, mixed> $payload
     */
    private function pendingNotificationFor(
        NotificationType $type,
        Channel $channel,
        DedupKey $dedupKey,
        array $payload,
        DateTimeImmutable $at,
    ): ?Notification {
        $key = $dedupKey->forChannel($channel);
        $existing = $this->notifications->findByDedupKey($key);

        if (null !== $existing) {
            return NotificationStatus::SENT === $existing->status() ? null : $existing;
        }

        $notification = new Notification(
            id: Uuid::v4()->toRfc4122(),
            type: $type,
            channel: $channel,
            payload: $payload,
            dedupKey: $key,
            createdAt: $at,
        );

        // Persist before sending: if delivery crashes the process, the dedup
        // record survives as PENDING and the next trigger retries it instead of
        // announcing the same occurrence a second time.
        $this->notifications->save($notification);

        return $notification;
    }

    private function deliver(Notification $notification, NotificationChannelInterface $adapter): void
    {
        try {
            $adapter->send($notification);
        } catch (Throwable $failure) {
            // Only the adapter is guarded: one channel failing must not stop the
            // others, but a rejected state transition is a bug we want to hear
            // about rather than convert into a "failed delivery".
            $reason = trim($failure->getMessage());
            $notification->markFailed('' === $reason ? $failure::class : $reason);
            $this->notifications->save($notification);

            return;
        }

        $notification->markSent(new DateTimeImmutable());
        $this->notifications->save($notification);
    }

    private function adapterFor(Channel $channel): ?NotificationChannelInterface
    {
        foreach ($this->channelAdapters as $adapter) {
            if ($adapter->channel() === $channel) {
                return $adapter;
            }
        }

        return null;
    }
}
