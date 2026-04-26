<?php

declare(strict_types=1);

namespace App\Module\Articles\Domain\Repository;

use App\Module\Articles\Domain\Entity\Article;

interface ArticleRepositoryInterface
{
    public function save(Article $article): void;
    public function existsByUrl(string $url): bool;
}
