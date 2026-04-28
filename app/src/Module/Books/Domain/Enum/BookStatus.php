<?php

declare(strict_types=1);

namespace App\Module\Books\Domain\Enum;

enum BookStatus: string
{
    case TO_READ = 'to_read';
    case READING = 'reading';
    case COMPLETED = 'completed';
}
