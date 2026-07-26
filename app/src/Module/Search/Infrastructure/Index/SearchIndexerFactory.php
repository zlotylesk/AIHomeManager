<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Index;

use App\Module\Search\Domain\Port\SearchIndexerInterface;
use App\Module\Search\Infrastructure\Engine\SearchEngineFactory;

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
    ): SearchIndexerInterface {
        return match (mb_strtolower(trim($backend))) {
            SearchEngineFactory::BACKEND_FULLTEXT => $fulltext,
            SearchEngineFactory::BACKEND_OPENSEARCH => $openSearch,
            default => throw SearchEngineFactory::unknownBackend($backend),
        };
    }
}
