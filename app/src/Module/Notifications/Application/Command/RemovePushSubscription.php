<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\Command;

/**
 * Forget a browser's push subscription — the endpoint is its identity.
 */
final readonly class RemovePushSubscription
{
    public function __construct(public string $endpoint)
    {
    }
}
