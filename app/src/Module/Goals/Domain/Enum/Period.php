<?php

declare(strict_types=1);

namespace App\Module\Goals\Domain\Enum;

/**
 * The rolling time window a goal target must be met within.
 * Backed values are the stable serialization/persistence contract.
 */
enum Period: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
}
