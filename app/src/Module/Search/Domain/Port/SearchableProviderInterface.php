<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\Port;

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
}
