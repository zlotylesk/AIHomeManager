<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\Query;

/**
 * List the films in the collection, optionally filtered by whether they have
 * been watched. A null {@see $watched} returns every movie.
 */
final readonly class GetMovies
{
    public function __construct(public ?bool $watched = null)
    {
    }
}
