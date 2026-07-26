<?php

declare(strict_types=1);

namespace App\Shared\Search;

/**
 * Marks a domain event whose occurrence makes a search document stale
 * (HMAI-363).
 *
 * The whole coupling story for incremental indexing, mirroring the reactive
 * notification rail (HMAI-281): a source module opts one of its events in by
 * implementing this, and Search listens for the *interface* — never for
 * `Tasks\Domain\Event\TaskCreated` or any other module's class. Both sides point
 * at the shared kernel, so deptrac stays at zero violations in both directions.
 *
 * Only the source module can judge whether an occurrence actually changed
 * anything searchable, which is why the decision lives on the event. Returning
 * null is the normal answer for an event that moves data the index does not
 * carry — re-indexing an unchanged document is pure churn, not safety.
 */
interface AffectsSearchIndex
{
    /**
     * The document this event made stale, or null when nothing searchable
     * changed.
     */
    public function toSearchDocumentRef(): ?SearchDocumentRef;
}
