<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Enum;

/**
 * Whether a transaction/category represents money coming in or going out.
 */
enum TransactionType: string
{
    case INCOME = 'income';
    case EXPENSE = 'expense';
}
