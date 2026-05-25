<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Query;

final readonly class GetAllTasks
{
    public function __construct(public ?string $status = null)
    {
    }
}
