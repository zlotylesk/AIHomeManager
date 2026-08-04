<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\QueryHandler;

use App\Module\Articles\Application\DTO\ArticleDTO;
use App\Module\Articles\Application\Query\GetAllArticles;
use App\Shared\Pagination\Page;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAllArticlesHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return Page<ArticleDTO> */
    public function __invoke(GetAllArticles $query): Page
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM articles');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, title, url, category, estimated_read_time, added_at, read_at, is_read
             FROM articles
             ORDER BY added_at DESC, id ASC LIMIT :limit OFFSET :offset',
            ['limit' => $query->page->perPage, 'offset' => $query->page->offset()],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return Page::of(array_map(ArticleDTO::fromRow(...), $rows), $total, $query->page);
    }
}
