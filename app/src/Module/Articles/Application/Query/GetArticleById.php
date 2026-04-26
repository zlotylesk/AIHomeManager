<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Query;

final class GetArticleById
{
    public function __construct(public readonly string $id) {}
}
