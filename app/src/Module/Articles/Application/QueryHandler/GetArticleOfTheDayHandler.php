<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\QueryHandler;

use App\Module\Articles\Application\DTO\ArticleDTO;
use App\Module\Articles\Application\Query\GetArticleOfTheDay;
use App\Module\Articles\Domain\Entity\ArticleDailyPick;
use App\Module\Articles\Domain\Repository\ArticleDailyPickRepositoryInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Redis;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetArticleOfTheDayHandler
{
    public function __construct(
        private Connection $connection,
        private ArticleDailyPickRepositoryInterface $pickRepository,
        private Redis $redis,
        private string $preferredCategory = '',
    ) {
    }

    public function __invoke(GetArticleOfTheDay $query): ?ArticleDTO
    {
        $cachedId = $this->redis->get('articles:today');
        if (false !== $cachedId) {
            $row = $this->connection->fetchAssociative(
                'SELECT id, title, url, category, estimated_read_time, added_at, read_at, is_read
                 FROM articles WHERE id = :id',
                ['id' => $cachedId]
            );

            return false !== $row ? ArticleDTO::fromRow($row) : null;
        }

        $recentIds = $this->pickRepository->findRecentlyPickedIds(7);
        $row = $this->findCandidate($recentIds, $this->preferredCategory ?: null);

        if (null === $row) {
            return null;
        }

        $this->pickRepository->save(new ArticleDailyPick(
            id: Uuid::v4()->toRfc4122(),
            articleId: $row['id'],
            pickedAt: new DateTimeImmutable(),
        ));

        $ttl = strtotime('tomorrow midnight') - time();
        $this->redis->setex('articles:today', max(1, $ttl), $row['id']);

        return ArticleDTO::fromRow($row);
    }

    private function findCandidate(array $excludeIds, ?string $preferredCategory): ?array
    {
        $excludeClause = '';
        $params = [];

        if (!empty($excludeIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeClause = "AND id NOT IN ({$placeholders})";
            $params = $excludeIds;
        }

        if (null !== $preferredCategory && '' !== $preferredCategory) {
            $row = $this->connection->fetchAssociative(
                "SELECT id, title, url, category, estimated_read_time, added_at, read_at, is_read
                 FROM articles
                 WHERE is_read = 0 {$excludeClause} AND category = ?
                 ORDER BY RAND() LIMIT 1",
                [...$params, $preferredCategory]
            );
            if (false !== $row) {
                return $row;
            }
        }

        $row = $this->connection->fetchAssociative(
            "SELECT id, title, url, category, estimated_read_time, added_at, read_at, is_read
             FROM articles
             WHERE is_read = 0 {$excludeClause}
             ORDER BY RAND() LIMIT 1",
            $params
        );

        return false !== $row ? $row : null;
    }
}
