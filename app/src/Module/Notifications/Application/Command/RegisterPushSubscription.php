<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\Command;

/**
 * Record a browser's push subscription, as handed over by the Push API.
 */
final readonly class RegisterPushSubscription
{
    public function __construct(
        public string $endpoint,
        public string $publicKey,
        public string $authToken,
    ) {
    }
}
