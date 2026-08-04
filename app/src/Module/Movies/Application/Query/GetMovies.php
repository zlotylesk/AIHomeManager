<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\Query;

use App\Shared\Pagination\PageRequest;

/**
 * List the films in the collection, optionally filtered by whether they have
 * been watched. A null {@see $watched} returns every movie.
 *
 * The result is always one {@see PageRequest} window wide — a caller that does
 * not ask for a window still gets the default page rather than the whole table.
 */
final readonly class GetMovies
{
    public function __construct(
        public ?bool $watched = null,
        public PageRequest $page = new PageRequest(),
    ) {
    }
}
