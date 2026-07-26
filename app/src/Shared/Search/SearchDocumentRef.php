<?php

declare(strict_types=1);

namespace App\Shared\Search;

use InvalidArgumentException;

/**
 * Points at the one search document a domain event made stale (HMAI-363).
 *
 * Primitives only, deliberately: this crosses a context boundary, so it cannot
 * carry `SearchResultType` (a Search Domain enum) without dragging the Search
 * module into every source module that emits an event. Search resolves the
 * string back to its own enum and ignores a type it does not index — the same
 * shape rule `NotificationRequest` follows.
 *
 * It says *what changed*, never *what to write*. Whether the document is
 * upserted or dropped depends on whether the source row still exists, which the
 * indexing side finds out by asking for the document; a source module has no
 * business knowing how Search stores anything.
 */
final readonly class SearchDocumentRef
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if ('' === trim($type)) {
            throw new InvalidArgumentException('A search document reference needs a type.');
        }

        if ('' === trim($id)) {
            throw new InvalidArgumentException('A search document reference needs an id.');
        }
    }
}
