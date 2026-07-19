<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Channel;

use App\Module\Notifications\Domain\Entity\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Backs {@see WebPushSenderInterface} with minishlink/web-push: signs the request
 * with our VAPID key pair and encrypts the payload for the subscription's keys.
 *
 * VAPID identifies this server to the push service, so no third-party provider
 * (FCM and friends) sits in the delivery path.
 */
final readonly class MinishlinkWebPushSender implements WebPushSenderInterface
{
    public function __construct(
        private string $publicKey,
        private string $privateKey,
        private string $subject,
    ) {
    }

    public function send(PushSubscription $subscription, string $payload): PushDeliveryResult
    {
        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => $this->subject,
                'publicKey' => $this->publicKey,
                'privateKey' => $this->privateKey,
            ]]);

            $report = $webPush->sendOneNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint(),
                    'publicKey' => $subscription->publicKey(),
                    'authToken' => $subscription->authToken(),
                ]),
                $payload,
            );
        } catch (Throwable $error) {
            // A malformed VAPID key pair or a transport-level failure: the
            // subscription itself is not implicated, so it must survive.
            return PushDeliveryResult::failed($error->getMessage());
        }

        if ($report->isSuccess()) {
            return PushDeliveryResult::delivered();
        }

        return $report->isSubscriptionExpired()
            ? PushDeliveryResult::expired($report->getReason())
            : PushDeliveryResult::failed($report->getReason());
    }
}
