<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Search\Infrastructure\Index\SearchIndexDefinition;
use OpenSearch\Client;
use Throwable;

/**
 * Puts the app-data search engine back into an unprovisioned state (HMAI-362).
 *
 * Unlike MySQL and Redis, the engine is not isolated per parallel worker — it is
 * one node with a global index namespace. tests/bootstrap.php gives each worker
 * its own alias; this trait is the other half, clearing whatever the previous
 * test in *this* worker left behind, so an assertion never reads a document a
 * different scenario indexed.
 */
trait ResetsSearchIndex
{
    private function resetSearchIndex(Client $client, SearchIndexDefinition $definition): void
    {
        foreach ($this->ownedIndices($client, $definition) as $index) {
            $this->deleteQuietly($client, $index);
        }

        // A concrete index carrying the alias name is the state a box that ran
        // the adapter before this ticket is left in, and it blocks the alias.
        $this->deleteQuietly($client, $definition->alias());
    }

    /**
     * @return list<string>
     */
    private function ownedIndices(Client $client, SearchIndexDefinition $definition): array
    {
        try {
            $rows = $client->cat()->indices([
                'index' => $definition->indexPattern(),
                'format' => 'json',
            ]);
        } catch (Throwable) {
            // No index matches the pattern yet.
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            $name = is_array($row) ? ($row['index'] ?? null) : null;
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function deleteQuietly(Client $client, string $index): void
    {
        try {
            $client->indices()->delete(['index' => $index]);
        } catch (Throwable) {
            // Absent — nothing to clean up.
        }
    }
}
