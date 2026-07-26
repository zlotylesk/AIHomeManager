<?php

declare(strict_types=1);

namespace App\Module\Search\Application\Command;

/**
 * Brings one search document back in step with its source (HMAI-363).
 *
 * It carries the document's identity, not its contents: the handler asks the
 * source what the document should look like now, and an answer of "nothing"
 * means the entity is gone and the document goes with it. That is what lets a
 * single command cover creation, update and deletion, and why a replayed
 * message is harmless — it re-reads the current truth rather than applying a
 * stale snapshot of it.
 *
 * Routed async, so a slow or unreachable engine never delays the request that
 * changed the data, and a failure retries through Messenger instead of
 * surfacing as a 500 on an unrelated write.
 */
final readonly class IndexSearchDocument
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
    }
}
