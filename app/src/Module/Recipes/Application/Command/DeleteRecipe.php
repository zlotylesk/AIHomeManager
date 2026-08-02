<?php

declare(strict_types=1);

namespace App\Module\Recipes\Application\Command;

final readonly class DeleteRecipe
{
    public function __construct(public string $id)
    {
    }
}
