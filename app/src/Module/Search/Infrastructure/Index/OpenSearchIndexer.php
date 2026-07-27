<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Index;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchableProviderInterface;
use App\Module\Search\Domain\Port\SearchIndexerInterface;
use App\Module\Search\Domain\ReadModel\SearchableDocument;
use DateTimeImmutable;
use OpenSearch\Client;
use OpenSearch\Common\Exceptions\Missing404Exception;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Throwable;

/**
 * Writes the OpenSearch index (HMAI-363) — the engine-side counterpart of the
 * FULLTEXT {@see SearchIndexer}, behind the same port.
 *
 * The bulk rebuild is **mark and sweep** rather than delete-then-fill: every
 * document is upserted under a deterministic id and stamped with the run's
 * timestamp, and only afterwards is anything still carrying an older stamp
 * removed. Emptying the index first would make search answer "nothing found"
 * for the length of the rebuild — the scheduler runs this every 15 minutes, so
 * that window would be a recurring outage rather than an edge case. It also
 * makes the run resumable for free: a crash leaves the index serving slightly
 * stale data, and the next run upserts over it without duplicating, because the
 * id is derived from the document's identity rather than generated.
 *
 * Rebuilding into a *new* index and swapping the alias (the HMAI-362 machinery)
 * is the right move for a mapping change, not for a routine refresh — it would
 * churn a full index every quarter hour.
 */
final readonly class OpenSearchIndexer implements SearchIndexerInterface
{
    /**
     * Documents per bulk request. Large enough that a few thousand documents
     * cost a handful of round trips, small enough that one request stays well
     * inside the default 100 MB HTTP limit.
     */
    private const int BATCH_SIZE = 500;

    /**
     * Millisecond precision, not seconds.
     *
     * The sweep keeps what this run stamped and drops what is strictly older, so
     * the stamp has to be finer-grained than the interval between two runs.
     * At second precision two rebuilds in the same second share a timestamp,
     * `lt` is false for the earlier generation, and documents whose source rows
     * were deleted in between survive indefinitely — which is exactly what the
     * regression test caught. A rebuild performs several synchronous round trips,
     * so it cannot repeat inside one millisecond.
     *
     * Kept inside `strict_date_optional_time`, the mapping's default parser —
     * microseconds would be rejected on write.
     */
    private const string STAMP_FORMAT = 'Y-m-d\TH:i:s.vP';

    public function __construct(
        private Client $client,
        private SearchableProviderInterface $provider,
        private SearchIndexManager $manager,
        private SearchIndexDefinition $definition,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function reindex(): int
    {
        // A scheduled rebuild on a fresh box would otherwise fail on a missing
        // index; provisioning is idempotent, so this costs one HEAD request.
        $this->manager->createIfMissing();

        $runAt = new DateTimeImmutable();
        $documents = $this->provider->documents();

        foreach (array_chunk($documents, self::BATCH_SIZE) as $batch) {
            $this->bulkIndex($batch, $runAt);
        }

        $this->refresh();
        $this->sweepDocumentsMissedBy($runAt);
        $this->cache->clear();

        return count($documents);
    }

    public function index(SearchableDocument $document): void
    {
        $this->manager->createIfMissing();

        try {
            $this->client->index([
                'index' => $this->definition->alias(),
                'id' => $this->documentId($document->type, $document->id),
                'body' => $this->source($document, new DateTimeImmutable()),
                // Single-document writes are rare and the whole point of them is
                // freshness, so pay the refresh here rather than leave a user
                // searching for something they just created.
                'refresh' => true,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('Indexing the document failed: %s', $e->getMessage()), 0, $e);
        }

        $this->cache->clear();
    }

    public function remove(SearchResultType $type, string $id): void
    {
        try {
            $this->client->delete([
                'index' => $this->definition->alias(),
                'id' => $this->documentId($type, $id),
                'refresh' => true,
            ]);
        } catch (Missing404Exception) {
            // Already gone, or the index has not been provisioned yet. The
            // caller wanted the document absent, and it is — a missing document
            // is not a failed deletion.
            return;
        } catch (Throwable $e) {
            // Anything else is the engine being unreachable or refusing the
            // write. Swallowing that would report success while the document
            // stayed searchable, and Messenger would never retry (HMAI-365 —
            // the catch used to cover every Throwable).
            throw new RuntimeException(sprintf('Removing the document from the index failed: %s', $e->getMessage()), 0, $e);
        }

        $this->cache->clear();
    }

    /**
     * @param list<SearchableDocument> $documents
     */
    private function bulkIndex(array $documents, DateTimeImmutable $runAt): void
    {
        $body = [];
        foreach ($documents as $document) {
            $body[] = ['index' => [
                '_index' => $this->definition->alias(),
                '_id' => $this->documentId($document->type, $document->id),
            ]];
            $body[] = $this->source($document, $runAt);
        }

        if ([] === $body) {
            return;
        }

        try {
            $response = $this->client->bulk(['body' => $body]);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('The bulk index request failed: %s', $e->getMessage()), 0, $e);
        }

        // A bulk request answers 200 even when individual documents were
        // rejected (a strict-mapping violation, say). Left unchecked, a rebuild
        // would report success while quietly indexing nothing.
        if (true === ($response['errors'] ?? false)) {
            throw new RuntimeException(sprintf('The bulk index request rejected documents: %s', $this->firstError($response)));
        }
    }

    /**
     * Removes what this run did not touch: a document still carrying an older
     * stamp has no source row behind it any more.
     */
    private function sweepDocumentsMissedBy(DateTimeImmutable $runAt): void
    {
        $this->client->deleteByQuery([
            'index' => $this->definition->alias(),
            // A document updated concurrently by the incremental path would
            // otherwise abort the sweep on a version conflict; it is newer than
            // this run, so skipping it is exactly right.
            'conflicts' => 'proceed',
            'refresh' => true,
            'body' => [
                'query' => ['range' => ['indexed_at' => ['lt' => $runAt->format(self::STAMP_FORMAT)]]],
            ],
        ]);
    }

    private function refresh(): void
    {
        $this->client->indices()->refresh(['index' => $this->definition->alias()]);
    }

    /**
     * Identity, not a surrogate: the same entity always lands on the same
     * document, which is what makes a replayed event or a re-run harmless.
     */
    private function documentId(SearchResultType $type, string $id): string
    {
        return $type->value.':'.$id;
    }

    /**
     * @return array<string, string>
     */
    private function source(SearchableDocument $document, DateTimeImmutable $at): array
    {
        return [
            'type' => $document->type->value,
            'source_id' => $document->id,
            'title' => $document->title,
            'content' => $document->content,
            'url' => $document->url,
            'indexed_at' => $at->format(self::STAMP_FORMAT),
        ];
    }

    /**
     * @param array<mixed, mixed> $response
     */
    private function firstError(array $response): string
    {
        foreach ($response['items'] ?? [] as $item) {
            $error = is_array($item) ? ($item['index']['error'] ?? null) : null;
            if (is_array($error)) {
                return sprintf('%s: %s', $error['type'] ?? 'unknown', $error['reason'] ?? 'no reason given');
            }
        }

        return 'no item carried an error description';
    }
}
