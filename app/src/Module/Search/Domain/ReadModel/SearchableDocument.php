<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\ReadModel;

use App\Module\Search\Domain\Enum\SearchResultType;

/**
 * A normalized, indexable record pulled from a source module. {@see $type}
 * identifies the module/entity kind, {@see $id} is the source entity's
 * identifier, {@see $title} the primary label, {@see $content} the additional
 * searchable body (author, description, category… — may be empty) and
 * {@see $url} the link that opens the entity.
 */
final readonly class SearchableDocument
{
    public function __construct(
        public SearchResultType $type,
        public string $id,
        public string $title,
        public string $content,
        public string $url,
    ) {
    }
}
