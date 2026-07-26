<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\Event;

use App\Shared\Search\SearchDocumentRef;

/**
 * Opts a task event into incremental search indexing (HMAI-363).
 *
 * Carried by the three events that genuinely move what the index holds: a task
 * being created, retitled, or deleted. `TaskCompleted` and `TaskCancelled`
 * deliberately do NOT use it — the indexed task document is its id and title, so
 * a status change leaves every searchable field identical and re-indexing it
 * would be churn without an effect.
 *
 * The type is a literal rather than `SearchResultType::TASK`: the shared-kernel
 * contract is primitives-only precisely so that this module never imports a
 * Search class. Search resolves it back to its enum and ignores what it cannot
 * map.
 */
trait RefreshesTaskSearchDocument
{
    public function toSearchDocumentRef(): ?SearchDocumentRef
    {
        return new SearchDocumentRef('task', $this->taskId);
    }
}
