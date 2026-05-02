<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\Enum;

enum TaskStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
