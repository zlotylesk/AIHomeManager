<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Index;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchIndexerInterface;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use App\Module\Search\Infrastructure\Engine\FallbackSearchEngine;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the standby index current so the fallback is worth falling back to
 * (HMAI-365).
 *
 * Without this, selecting OpenSearch as the read backend would also stop
 * anything from writing the MySQL `search_documents` table — the indexer factory
 * picks one writer, and it picks the one matching the reader. The table would
 * then freeze at whatever it held the day the flag was flipped, and
 * {@see FallbackSearchEngine} would quietly serve a months-old library during an
 * outage: deleted entities still listed, everything added since missing. A
 * fallback onto an unmaintained index is a fallback in name only, and its
 * failure mode (plausible but wrong results) is worse than an honest error.
 *
 * Failure handling is asymmetric on purpose. The **primary** is the index being
 * read, so a write failure there propagates — the caller is a Messenger handler
 * and retrying is exactly right. The **standby** is insurance: a failure is
 * logged and swallowed, because losing the insurance must not also lose the
 * write that was actually being served. The 15-minute bulk rebuild re-attempts
 * it anyway, so a transient standby failure heals itself.
 */
final readonly class DualWriteSearchIndexer implements SearchIndexerInterface
{
    public function __construct(
        private SearchIndexerInterface $primary,
        private SearchIndexerInterface $standby,
        private LoggerInterface $logger,
    ) {
    }

    public function reindex(): int
    {
        $indexed = $this->primary->reindex();

        // The count comes from the primary: it is the index that answers reads,
        // so it is the one an operator running `app:search:populate` is asking
        // about.
        $this->keepStandbyWarm('reindex', fn () => $this->standby->reindex());

        return $indexed;
    }

    public function index(SearchableDocument $document): void
    {
        $this->primary->index($document);

        $this->keepStandbyWarm('index', fn () => $this->standby->index($document));
    }

    public function remove(SearchResultType $type, string $id): void
    {
        $this->primary->remove($type, $id);

        $this->keepStandbyWarm('remove', fn () => $this->standby->remove($type, $id));
    }

    private function keepStandbyWarm(string $operation, callable $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            $this->logger->warning('Failed to update the standby search index.', [
                'operation' => $operation,
                'standby' => $this->standby::class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
