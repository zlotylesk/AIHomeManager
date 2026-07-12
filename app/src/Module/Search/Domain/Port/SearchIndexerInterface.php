<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\Port;

/**
 * Rebuilds the product-wide search index from the current source data. Backed by
 * a DBAL adapter in Infrastructure (HMAI-268) that reads the
 * {@see SearchableProviderInterface} documents and materializes them into the
 * FULLTEXT-indexed `search_documents` table the {@see SearchEngineInterface}
 * queries.
 */
interface SearchIndexerInterface
{
    /**
     * Rebuilds the index and returns the number of indexed documents.
     */
    public function reindex(): int;
}
