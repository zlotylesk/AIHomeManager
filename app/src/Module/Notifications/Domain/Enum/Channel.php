<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\Enum;

/**
 * The delivery channel a notification is sent through. Backed values are the
 * stable serialization/persistence contract.
 */
enum Channel: string
{
    case EMAIL = 'email';
    case PUSH = 'push';
}
