<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Command;

final class MarkArticleAsRead
{
    public function __construct(public readonly string $articleId) {}
}
