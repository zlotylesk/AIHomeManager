<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\Command;

/**
 * Turn one delivery channel on or off for one notification type. The raw
 * {@see $type}/{@see $channel} strings are validated against the enums in the
 * handler.
 */
final readonly class SetChannelPreference
{
    public function __construct(
        public string $type,
        public string $channel,
        public bool $enabled,
    ) {
    }
}
