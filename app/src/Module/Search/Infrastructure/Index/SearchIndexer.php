<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Index;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchableProviderInterface;
use App\Module\Search\Domain\Port\SearchIndexerInterface;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Writes the `search_documents` FULLTEXT table — the MySQL side of the index
 * writer port.
 *
 * The rebuild pulls every {@see SearchableDocument} from the composite
 * {@see SearchableProviderInterface} and replaces the table's contents in one
 * transaction (deterministic and idempotent — a re-run yields the same rows).
 * Because that happens inside a transaction, no reader ever observes the empty
 * intermediate state, which is why this side can afford delete-then-fill where
 * the OpenSearch one has to mark and sweep.
 *
 * The single-document writes (HMAI-363) keep one entity fresh without a full
 * rebuild; they lean on the table's (type, source_id) primary key for
 * deduplication, so a replayed message updates instead of inserting.
 */
final readonly class SearchIndexer implements SearchIndexerInterface
{
    public function __construct(
        private Connection $connection,
        private SearchableProviderInterface $provider,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function reindex(): int
    {
        $documents = $this->provider->documents();

        $this->connection->transactional(function (Connection $connection) use ($documents): void {
            $connection->executeStatement('DELETE FROM search_documents');
            foreach ($documents as $document) {
                $connection->insert('search_documents', [
                    'type' => $document->type->value,
                    'source_id' => $document->id,
                    'title' => $document->title,
                    'content' => $document->content,
                    'url' => $document->url,
                ]);
            }
        });

        // The index changed, so any cached search results are now stale.
        $this->cache->clear();

        return count($documents);
    }

    public function index(SearchableDocument $document): void
    {
        // `search_documents` is keyed by (type, source_id), so the upsert is the
        // primary key doing the deduplication — replaying the same event writes
        // the same row rather than a second one.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO search_documents (type, source_id, title, content, url)
                VALUES (:type, :source_id, :title, :content, :url)
                ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content), url = VALUES(url)
                SQL,
            [
                'type' => $document->type->value,
                'source_id' => $document->id,
                'title' => $document->title,
                'content' => $document->content,
                'url' => $document->url,
            ],
        );

        $this->cache->clear();
    }

    public function remove(SearchResultType $type, string $id): void
    {
        $this->connection->executeStatement(
            'DELETE FROM search_documents WHERE type = :type AND source_id = :source_id',
            ['type' => $type->value, 'source_id' => $id],
        );

        $this->cache->clear();
    }
}
