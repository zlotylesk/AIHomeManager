<?php

declare(strict_types=1);

namespace App\Module\Search\Infrastructure\Index;

use DateTimeImmutable;
use OpenSearch\Client;
use RuntimeException;
use Throwable;

/**
 * Provisions and migrates the OpenSearch index behind an alias (HMAI-362, epic
 * HMAI-359).
 *
 * Everything reads and writes through the alias, never through a physical index
 * name. That indirection is the whole point: OpenSearch mappings are largely
 * immutable once a field exists, so changing an analyzer means building a second
 * index and moving the name over — and because the move is a single atomic
 * `_aliases` call, searches keep being answered throughout, by the old index one
 * moment and the new one the next, with no window where the alias resolves to
 * nothing.
 *
 * The schema itself lives in {@see SearchIndexDefinition}; this class only knows
 * how to apply it.
 */
final readonly class SearchIndexManager
{
    public function __construct(
        private Client $client,
        private SearchIndexDefinition $definition,
    ) {
    }

    /**
     * The physical index the alias currently resolves to, or null when the alias
     * does not exist yet.
     */
    public function currentIndex(): ?string
    {
        $alias = $this->definition->alias();

        if (!$this->client->indices()->existsAlias(['name' => $alias])) {
            return null;
        }

        // Keyed by physical index name; the alias is only ever pointed at one,
        // so the first key is the answer.
        $names = array_keys($this->client->indices()->getAlias(['name' => $alias]));
        $first = $names[0] ?? null;

        return is_string($first) ? $first : null;
    }

    /**
     * Creates the index and points the alias at it, unless the alias already
     * resolves. Idempotent, so it is safe to run on every deploy.
     *
     * @return string the physical index behind the alias
     */
    public function createIfMissing(): string
    {
        $existing = $this->currentIndex();
        if (null !== $existing) {
            return $existing;
        }

        $this->guardAliasNameIsFree();

        $index = $this->createIndex();
        $this->applyAliasActions([
            ['add' => ['index' => $index, 'alias' => $this->definition->alias()]],
        ]);

        return $index;
    }

    /**
     * Rebuilds the index under the current definition without an outage: create
     * a new physical index, copy every document into it, then move the alias
     * across in one atomic step and drop the superseded index.
     *
     * Copying via `_reindex` (rather than re-running the source-of-truth
     * pipeline) is what makes this cheap enough to use for a pure analyzer or
     * mapping change: the documents are already there, only the way they are
     * analyzed differs. A schema change that needs *new* data is a pipeline run
     * (HMAI-363) followed by this.
     *
     * @return string the new physical index behind the alias
     */
    public function reindex(): string
    {
        $source = $this->currentIndex();
        if (null === $source) {
            // Nothing to migrate from — on an unprovisioned engine "reindex"
            // can only sensibly mean "create", and failing here would make the
            // console command order-dependent for no reason.
            return $this->createIfMissing();
        }

        $target = $this->createIndex();

        try {
            $this->client->reindex([
                'body' => [
                    'source' => ['index' => $source],
                    'dest' => ['index' => $target],
                ],
                'wait_for_completion' => true,
                'refresh' => true,
            ]);
        } catch (Throwable $e) {
            // The copy failed, so the alias was never touched and the old index
            // is still serving. Drop the half-built one instead of leaving an
            // orphan behind on every failed attempt. The rollback deliberately
            // covers only the copy: once the alias has moved, deleting the
            // target would take the live index with it.
            try {
                $this->client->indices()->delete(['index' => $target]);
            } catch (Throwable) {
                // Best effort — the original failure is the one worth reporting.
            }

            throw $e;
        }

        // Remove and add in one call: two calls would leave a moment where the
        // alias resolves to nothing (or to both indices at once, which makes
        // writes ambiguous).
        $this->applyAliasActions([
            ['remove' => ['index' => $source, 'alias' => $this->definition->alias()]],
            ['add' => ['index' => $target, 'alias' => $this->definition->alias()]],
        ]);

        // The old index is unreachable now that the alias moved. Keeping it
        // would accumulate a full copy of the corpus per migration; a retention
        // policy for deliberately-kept generations is HMAI-365's scope.
        $this->client->indices()->delete(['index' => $source]);

        return $target;
    }

    private function createIndex(): string
    {
        $index = $this->definition->newIndexName(new DateTimeImmutable());

        $this->client->indices()->create([
            'index' => $index,
            'body' => $this->definition->body(),
        ]);

        return $index;
    }

    /**
     * @param list<array<string, mixed>> $actions
     */
    private function applyAliasActions(array $actions): void
    {
        $this->client->indices()->updateAliases(['body' => ['actions' => $actions]]);
    }

    /**
     * An index literally named like the alias makes the alias impossible to
     * create, and OpenSearch reports that as a bare "invalid alias name" that
     * tells an operator nothing. Any box that ran the adapter before this ticket
     * has exactly that leftover, so the error names the fix.
     */
    private function guardAliasNameIsFree(): void
    {
        $alias = $this->definition->alias();

        try {
            $occupied = $this->client->indices()->exists(['index' => $alias]);
        } catch (Throwable $e) {
            throw new RuntimeException(sprintf('The search engine is unreachable: %s', $e->getMessage()), 0, $e);
        }

        if ($occupied) {
            throw new RuntimeException(sprintf('"%s" already exists as a concrete index, so it cannot also be an alias. Delete it (DELETE /%s) and run the command again — nothing owns that data, the indexing pipeline rebuilds it.', $alias, $alias));
        }
    }
}
