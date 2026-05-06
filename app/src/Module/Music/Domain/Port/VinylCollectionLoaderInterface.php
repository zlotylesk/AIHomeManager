<?php

declare(strict_types=1);

namespace App\Module\Music\Domain\Port;

interface VinylCollectionLoaderInterface
{
    /**
     * Fetch full collection from upstream and persist to cache.
     * Long-running by design (paginated + rate-limited) — call from a background worker, never from a request.
     */
    public function fetchAndCacheCollection(string $username): void;
}
