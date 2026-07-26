<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\Port;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ReadModel\SearchableDocument;

/**
 * Writes the product-wide search index. Backed per backend in Infrastructure —
 * the FULLTEXT `search_documents` table (HMAI-268) or the OpenSearch index
 * (HMAI-363) — behind the same contract, so the pipeline above it never learns
 * which engine is active.
 *
 * The two halves answer different needs. {@see reindex()} establishes the
 * truth-in-full and is what catches up modules that emit no domain events at
 * all; {@see index()} and {@see remove()} keep a single document fresh the
 * moment something changes, without paying for a sweep of everything else.
 * Both are idempotent: a document's identity is its type plus its source id, so
 * a repeated write updates rather than duplicates.
 */
interface SearchIndexerInterface
{
    /**
     * Rebuilds the index and returns the number of indexed documents.
     */
    public function reindex(): int;

    /**
     * Writes one document, replacing any earlier copy of it.
     */
    public function index(SearchableDocument $document): void;

    /**
     * Drops one document. Removing what is not there is a success — the caller
     * wanted it gone, and it is.
     */
    public function remove(SearchResultType $type, string $id): void;
}
