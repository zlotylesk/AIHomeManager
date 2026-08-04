<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Query;

use App\Shared\Pagination\PageRequest;

/**
 * List the recipe catalog, optionally narrowed by tag and/or a phrase in the
 * title. The two filters are independent and combine with AND; a null field
 * means "do not filter on this".
 *
 * A blank string is treated as no filter rather than as a search for the empty
 * value — an emptied text input submits `''`, and answering "no recipes" to a
 * user who just cleared the box would be a wrong answer that looks like a
 * correct one.
 */
final readonly class GetRecipes
{
    public function __construct(
        public ?string $tag = null,
        public ?string $phrase = null,
        public PageRequest $page = new PageRequest(),
    ) {
    }
}
