<?php

declare(strict_types=1);

namespace App\Module\Goals\Domain\ReadModel;

/**
 * The computed state of a goal within its current period window: how much of
 * the {@see $target} has been {@see $achieved}, the capped {@see $percent}
 * (0–100), and whether the target has been reached.
 */
final readonly class GoalProgress
{
    public function __construct(
        public int $target,
        public int $achieved,
        public int $percent,
        public bool $isMet,
    ) {
    }
}
