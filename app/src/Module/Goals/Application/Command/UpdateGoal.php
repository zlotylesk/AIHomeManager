<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\Command;

/**
 * Adjust an existing goal's target and/or period. The measured activity type is
 * immutable and therefore not part of this command.
 */
final readonly class UpdateGoal
{
    public function __construct(
        public string $id,
        public int $target,
        public string $period,
    ) {
    }
}
