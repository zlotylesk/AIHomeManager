<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\Command;

/**
 * Set or clear the user's own rating of a film. A null {@see $rating} clears the
 * rating; a value is range-validated by the Rating VO in the handler.
 */
final readonly class RateMovie
{
    public function __construct(
        public string $id,
        public ?int $rating,
    ) {
    }
}
