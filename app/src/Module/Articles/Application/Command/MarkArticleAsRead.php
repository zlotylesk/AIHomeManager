<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Command;

final readonly class MarkArticleAsRead
{
    public function __construct(public string $articleId)
    {
    }
}
