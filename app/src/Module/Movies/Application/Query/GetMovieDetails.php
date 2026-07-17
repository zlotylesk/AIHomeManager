<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\Query;

/**
 * Fetch a single film by id. The handler returns null when the movie does not
 * exist, which the controller maps to 404.
 */
final readonly class GetMovieDetails
{
    public function __construct(public string $id)
    {
    }
}
