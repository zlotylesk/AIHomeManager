<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Engine;

use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps global search answering when the primary engine does not (HMAI-365,
 * epic HMAI-359).
 *
 * A search backend that is a single point of failure is a worse deal than the
 * one it replaced: the OpenSearch engine is the better answer, but MySQL
 * FULLTEXT is always there, so an outage should cost relevance rather than the
 * feature. This decorator makes that trade automatically — the primary is tried
 * first, and any failure degrades to the standby with a warning rather than a
 * 500.
 *
 * Three things it deliberately does **not** do:
 *
 * - It does not swallow a *total* failure. If the standby throws too, the
 *   exception propagates: at that point search really is broken, and reporting
 *   "no results" would be a lie about the library's contents. Only the engine
 *   layer degrades; a broken database is still an error.
 * - It does not probe the primary's health first. A probe is a second round trip
 *   that can succeed while the query behind it fails, so the query itself is the
 *   check.
 * - It is not installed when FULLTEXT is already the active backend
 *   ({@see SearchEngineFactory}) — wrapping an engine in a fallback to itself
 *   would only add a layer that can never fire.
 *
 * The pairing with the write side matters as much as this class: the standby is
 * only worth falling back to while something keeps its index current, which is
 * why selecting OpenSearch also turns on the dual-write indexer.
 *
 * One interaction worth stating rather than rediscovering: the Redis result
 * cache (HMAI-271) sits *outside* this decorator and keys on its class, so a
 * degraded answer is cached like any other and survives the engine's recovery
 * for up to the 300 s TTL. That is deliberate — unlike the Insights cache, which
 * refuses to store an "unavailable" reading, a degraded answer here is still
 * correct, merely ranked less well, and caching it means an outage costs one
 * engine timeout per distinct query instead of one per request.
 */
final readonly class FallbackSearchEngine implements SearchEngineInterface
{
    public function __construct(
        private SearchEngineInterface $primary,
        private SearchEngineInterface $standby,
        private LoggerInterface $logger,
    ) {
    }

    public function search(SearchQuery $query): array
    {
        try {
            return $this->primary->search($query);
        } catch (Throwable $e) {
            $this->reportDegrade('search', $e);

            return $this->standby->search($query);
        }
    }

    public function facets(SearchQuery $query): array
    {
        try {
            return $this->primary->facets($query);
        } catch (Throwable $e) {
            $this->reportDegrade('facets', $e);

            return $this->standby->facets($query);
        }
    }

    private function reportDegrade(string $operation, Throwable $e): void
    {
        // Warning, not error: the user is being served. But it must be visible —
        // a silent degrade means running on the fallback for weeks without
        // anyone noticing the engine died, and the first sign would be someone
        // complaining that search "got worse".
        $this->logger->warning('Search degraded to the fallback engine.', [
            'operation' => $operation,
            'primary' => $this->primary::class,
            'standby' => $this->standby::class,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
