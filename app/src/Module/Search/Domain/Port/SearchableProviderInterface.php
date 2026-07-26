<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\Port;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ReadModel\SearchableDocument;

/**
 * Supplies the product-wide set of indexable documents that the search engine
 * folds into its index, without the Search module coupling to any source
 * module's Domain or Persistence. Infrastructure adapters back it per source
 * module (Articles/Books/Series/Music/Tasks), each reading its own tables.
 */
interface SearchableProviderInterface
{
    /**
     * Every indexable document exposed by this provider.
     *
     * @return SearchableDocument[]
     */
    public function documents(): array;

    /**
     * One document by source identity, or null when this provider does not
     * expose it — either because the type belongs to another module or because
     * the source row is gone.
     *
     * This is what makes incremental indexing possible (HMAI-363): an event says
     * *which* document went stale, and the answer here decides what happens to
     * it. A null is not an error — it is how a deletion is recognised, without
     * the source module having to say "delete" in its event.
     */
    public function documentFor(SearchResultType $type, string $id): ?SearchableDocument;
}
