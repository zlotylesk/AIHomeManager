<?php

declare(strict_types=1);

namespace App\Module\Goals\Application\Command;

/**
 * Define a new goal. The activity {@see $type} is fixed for the life of the
 * goal; validation of the raw inputs happens in the handler.
 */
final readonly class CreateGoal
{
    public function __construct(
        public string $type,
        public int $target,
        public string $period,
    ) {
    }
}
