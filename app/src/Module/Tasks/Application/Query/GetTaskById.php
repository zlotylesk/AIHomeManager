<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Query;

final readonly class GetTaskById
{
    public function __construct(public string $id)
    {
    }
}
