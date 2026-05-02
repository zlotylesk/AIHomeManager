<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Query;

final readonly class GetArticleById
{
    public function __construct(public string $id)
    {
    }
}
