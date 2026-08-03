<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Query;

/**
 * One recipe with its ingredients and steps. The handler answers `null` for an
 * unknown id and the HTTP layer maps that to a 404 (HMAI-392) — a read that
 * cannot find its subject is not an exceptional condition down here.
 */
final readonly class GetRecipeDetail
{
    public function __construct(public string $id)
    {
    }
}
