<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\Enum;

/**
 * Delivery state of a single notification. A notification starts PENDING, then
 * settles into SENT or FAILED once a channel adapter has run. Backed values are
 * the stable serialization/persistence contract.
 */
enum NotificationStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';
}
