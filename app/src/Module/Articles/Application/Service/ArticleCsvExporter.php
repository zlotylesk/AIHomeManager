<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Service;

use Doctrine\DBAL\Connection;
use Generator;

final readonly class ArticleCsvExporter
{
    /** @var list<string> */
    public const array HEADERS = ['title', 'url', 'category', 'readAt', 'isRead'];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Streams article rows one at a time via DBAL cursor — no fetchAllAssociative
     * so large exports don't load the whole table into PHP memory. Matches
     * HMAI-36 dev notes.
     *
     * @return Generator<int, list<scalar|null>>
     */
    public function rows(?string $status = null): Generator
    {
        $sql = 'SELECT title, url, category, read_at, is_read
                FROM articles';
        $params = [];

        if ('read' === $status) {
            $sql .= ' WHERE is_read = 1';
        } elseif ('unread' === $status) {
            $sql .= ' WHERE is_read = 0';
        }

        $sql .= ' ORDER BY added_at DESC';

        $result = $this->connection->executeQuery($sql, $params);
        while (false !== ($row = $result->fetchAssociative())) {
            yield [
                $row['title'],
                $row['url'],
                $row['category'],
                $row['read_at'],
                (bool) $row['is_read'] ? '1' : '0',
            ];
        }
    }
}
