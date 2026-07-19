<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\Entity;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One browser's consent to receive push notifications: the push service endpoint
 * the message is delivered to, plus the two keys the payload is encrypted with.
 *
 * A single user still has several of these — one per browser/device — so the push
 * channel fans one notification out to every stored subscription.
 *
 * Subscriptions expire outside our control (the browser is uninstalled, the user
 * revokes permission). The push service reports that with 404/410 and the channel
 * removes the row; nothing here can detect it on its own.
 */
final readonly class PushSubscription
{
    public function __construct(
        private string $id,
        private string $endpoint,
        private string $publicKey,
        private string $authToken,
        private DateTimeImmutable $createdAt,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Push subscription id cannot be empty.');
        }

        if ('' === trim($endpoint)) {
            throw new InvalidArgumentException('Push subscription endpoint cannot be empty.');
        }

        if ('' === trim($publicKey)) {
            throw new InvalidArgumentException('Push subscription public key (p256dh) cannot be empty.');
        }

        if ('' === trim($authToken)) {
            throw new InvalidArgumentException('Push subscription auth token cannot be empty.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * The push service URL this browser is reachable at — also the subscription's
     * natural identity, since the push service mints a distinct one per browser.
     */
    public function endpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * The subscription's P-256 ECDH public key ("p256dh" in the browser's
     * PushSubscription JSON).
     */
    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * The subscription's shared authentication secret ("auth").
     */
    public function authToken(): string
    {
        return $this->authToken;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
