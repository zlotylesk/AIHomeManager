<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Index;

use App\Module\Search\Domain\Port\SearchIndexerInterface;
use App\Module\Search\Infrastructure\Engine\SearchEngineFactory;
use Psr\Log\LoggerInterface;

/**
 * Picks the index writer that matches the active read backend (HMAI-363).
 *
 * It reads the same `SEARCH_ENGINE_BACKEND` flag as
 * {@see SearchEngineFactory} and rejects the same values, on purpose: writing
 * to one backend while reading from the other would leave search answering from
 * an index nothing maintains — the one failure mode that looks exactly like
 * "there is no data" and would take ages to diagnose.
 *
 * A factory rather than an alias for the reason the engine one gives: a Symfony
 * alias is resolved at compile time and cannot read an env var.
 */
final class SearchIndexerFactory
{
    public static function create(
        string $backend,
        SearchIndexerInterface $fulltext,
        SearchIndexerInterface $openSearch,
        LoggerInterface $logger,
    ): SearchIndexerInterface {
        return match (mb_strtolower(trim($backend))) {
            SearchEngineFactory::BACKEND_FULLTEXT => $fulltext,
            // Selecting OpenSearch also keeps the FULLTEXT table current
            // (HMAI-365). The read side degrades to it when the engine is
            // unreachable, and a standby index nothing maintains would answer
            // that outage with a frozen snapshot of the library — plausible,
            // wrong, and harder to notice than an error.
            SearchEngineFactory::BACKEND_OPENSEARCH => new DualWriteSearchIndexer($openSearch, $fulltext, $logger),
            default => throw SearchEngineFactory::unknownBackend($backend),
        };
    }
}
