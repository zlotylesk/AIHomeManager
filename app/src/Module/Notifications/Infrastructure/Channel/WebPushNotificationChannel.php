<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Channel;

use App\Module\Notifications\Domain\Entity\Notification;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Port\NotificationChannelInterface;
use App\Module\Notifications\Domain\Repository\PushSubscriptionRepositoryInterface;
use RuntimeException;
use Twig\Environment;
use Twig\Error\Error as TwigError;

/**
 * Delivers notifications as Web Push messages, fanning one notification out to
 * every browser the user has subscribed from.
 *
 * Rendering mirrors the e-mail channel: a per-type Twig template supplies the
 * title and body, so adding a notification type never means branching here. The
 * templates stay plain text and the JSON envelope the Service Worker consumes is
 * assembled in PHP — building JSON inside Twig would put escaping in the wrong
 * hands.
 *
 * Like the e-mail channel this send is synchronous; DispatchNotification is the
 * async-routed command, which keeps the push service's answer observable here.
 */
final readonly class WebPushNotificationChannel implements NotificationChannelInterface
{
    private const string TEMPLATE_DIR = 'notifications/push';

    public function __construct(
        private PushSubscriptionRepositoryInterface $subscriptions,
        private WebPushSenderInterface $sender,
        private Environment $twig,
    ) {
    }

    public function channel(): Channel
    {
        return Channel::PUSH;
    }

    public function send(Notification $notification): void
    {
        $subscriptions = $this->subscriptions->findAll();

        if ([] === $subscriptions) {
            // Reported as a failure rather than silently succeeding: the channel
            // was chosen but nothing could receive the message, and the engine's
            // retry picks it up once a browser subscribes.
            throw new RuntimeException('No push subscriptions are registered.');
        }

        $payload = $this->renderPayload($notification);
        $delivered = 0;
        $reasons = [];

        foreach ($subscriptions as $subscription) {
            $result = $this->sender->send($subscription, $payload);

            if ($result->successful) {
                ++$delivered;
                continue;
            }

            if ($result->subscriptionExpired) {
                // The browser is gone for good; keeping the row would retry a
                // dead endpoint on every future notification.
                $this->subscriptions->remove($subscription);
            }

            $reasons[] = sprintf('%s: %s', $subscription->endpoint(), $result->reason);
        }

        // One reachable device is enough for the occurrence to count as announced;
        // only a complete miss is a failed delivery.
        if (0 === $delivered) {
            throw new RuntimeException(sprintf('No push subscription accepted the notification (%s).', implode('; ', $reasons)));
        }
    }

    private function renderPayload(Notification $notification): string
    {
        $template = sprintf('%s/%s.txt.twig', self::TEMPLATE_DIR, $notification->type()->value);
        $context = ['payload' => $notification->payload()];

        try {
            $rendered = $this->twig->load($template);
            $title = trim($rendered->renderBlock('title', $context));
            $body = trim($rendered->renderBlock('body', $context));
        } catch (TwigError $error) {
            throw new RuntimeException(sprintf('Could not render the "%s" push notification: %s', $template, $error->getMessage()), previous: $error);
        }

        $envelope = ['title' => $title, 'body' => $body];
        $url = $this->linkableUrl($notification->payload());

        if (null !== $url) {
            $envelope['url'] = $url;
        }

        return json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * Payload URLs can originate from user-entered data (an imported article's
     * own address), and the Service Worker opens this on click — so anything
     * outside http(s) is dropped rather than handed to the browser.
     *
     * @param array<string, mixed> $payload
     */
    private function linkableUrl(array $payload): ?string
    {
        $url = $payload['url'] ?? null;

        if (!\is_string($url)) {
            return null;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : null;
    }
}
