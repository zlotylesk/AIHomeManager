<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\Query;

final readonly class GetMonthlyBudgetReport
{
    public function __construct(public string $month)
    {
    }
}
