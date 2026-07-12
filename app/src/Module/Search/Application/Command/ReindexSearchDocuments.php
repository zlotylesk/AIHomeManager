<?php

declare(strict_types=1);

namespace App\Module\Search\Application\Command;

/**
 * Rebuilds the search index from the current source data. No payload — the index
 * is a single product-wide projection. Fired by the Scheduler (every 15 min) so
 * the FULLTEXT index tracks source changes; runs synchronously on command.bus.
 */
final readonly class ReindexSearchDocuments
{
}
