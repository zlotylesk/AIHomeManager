<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\QueryHandler;

use App\Module\Articles\Application\DTO\ArticleDTO;
use App\Module\Articles\Application\Query\GetAllArticles;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAllArticlesHandler
{
    public function __construct(private Connection $connection) {}

    /** @return ArticleDTO[] */
    public function __invoke(GetAllArticles $query): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, title, url, category, estimated_read_time, added_at, read_at, is_read
             FROM articles
             ORDER BY added_at DESC'
        );

        return array_map(ArticleDTO::fromRow(...), $rows);
    }
}
