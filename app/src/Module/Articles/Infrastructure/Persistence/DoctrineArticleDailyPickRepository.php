<?php

declare(strict_types=1);

namespace App\Module\Articles\Infrastructure\Persistence;

use App\Module\Articles\Domain\Entity\ArticleDailyPick;
use App\Module\Articles\Domain\Repository\ArticleDailyPickRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineArticleDailyPickRepository implements ArticleDailyPickRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(ArticleDailyPick $pick): void
    {
        $this->em->persist($pick);
        $this->em->flush();
    }

    public function findRecentlyPickedIds(int $days): array
    {
        $cutoff = new DateTimeImmutable("-{$days} days")->format('Y-m-d H:i:s');

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT article_id FROM article_daily_picks WHERE picked_at >= :cutoff',
            ['cutoff' => $cutoff]
        );

        return array_column($rows, 'article_id');
    }
}
