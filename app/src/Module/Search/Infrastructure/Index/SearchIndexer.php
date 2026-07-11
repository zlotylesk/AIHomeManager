<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Index;

use App\Module\Search\Domain\Port\SearchableProviderInterface;
use App\Module\Search\Domain\Port\SearchIndexerInterface;
use Doctrine\DBAL\Connection;

/**
 * Rebuilds the `search_documents` FULLTEXT index by pulling every
 * {@see \App\Module\Search\Domain\ReadModel\SearchableDocument} from the
 * composite {@see SearchableProviderInterface} and replacing the table's
 * contents in one transaction (deterministic, idempotent — a re-run yields the
 * same rows).
 */
final readonly class SearchIndexer implements SearchIndexerInterface
{
    public function __construct(
        private Connection $connection,
        private SearchableProviderInterface $provider,
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

        return count($documents);
    }
}
