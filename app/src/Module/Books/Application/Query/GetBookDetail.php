<?php

declare(strict_types=1);

namespace App\Module\Books\Application\Query;

final readonly class GetBookDetail
{
    public function __construct(public string $id) {}
}
