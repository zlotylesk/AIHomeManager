<?php

declare(strict_types=1);

namespace App\Module\Articles\Infrastructure\Persistence;

use App\Module\Articles\Domain\Entity\Article;
use App\Module\Articles\Domain\Repository\ArticleRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineArticleRepository implements ArticleRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(Article $article): void
    {
        $this->em->persist($article);
        $this->em->flush();
    }

    public function existsByUrl(string $url): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM articles WHERE url = :url',
            ['url' => $url],
        );
    }

    public function findById(string $id): ?Article
    {
        return $this->em->find(Article::class, $id);
    }

    public function delete(Article $article): void
    {
        $this->em->remove($article);
        $this->em->flush();
    }
}
