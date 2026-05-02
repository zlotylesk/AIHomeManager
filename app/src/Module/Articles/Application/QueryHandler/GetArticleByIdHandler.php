<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\QueryHandler;

use App\Module\Articles\Application\DTO\ArticleDTO;
use App\Module\Articles\Application\Query\GetArticleById;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetArticleByIdHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(GetArticleById $query): ?ArticleDTO
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, title, url, category, estimated_read_time, added_at, read_at, is_read
             FROM articles WHERE id = :id',
            ['id' => $query->id]
        );

        return false !== $row ? ArticleDTO::fromRow($row) : null;
    }
}
