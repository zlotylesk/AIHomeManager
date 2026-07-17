<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\Port;

use App\Module\Notifications\Domain\Entity\Notification;
use App\Module\Notifications\Domain\Enum\Channel;
use RuntimeException;

/**
 * Delivery contract implemented once per channel (e-mail, push). Infrastructure
 * adapters back it, so the Domain stays free of Mailer/WebPush specifics.
 *
 * Implementations are collected by the dispatch engine and matched against a
 * notification via {@see self::channel()}.
 */
interface NotificationChannelInterface
{
    /**
     * The channel this adapter delivers.
     */
    public function channel(): Channel;

    /**
     * Deliver the notification, rendering it from the notification's payload.
     *
     * Recording the outcome on the aggregate is the caller's job — an adapter
     * reports failure by throwing.
     *
     * @throws RuntimeException when delivery fails
     */
    public function send(Notification $notification): void;
}
