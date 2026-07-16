<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\Command;

/**
 * Rename an existing film. The title is the only mutable field the flat Movie
 * aggregate currently exposes; validation happens in the handler.
 */
final readonly class UpdateMovie
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
