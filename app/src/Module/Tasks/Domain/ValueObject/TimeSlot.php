<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class TimeSlot
{
    public function __construct(
        private DateTimeImmutable $startDateTime,
        private DateTimeImmutable $endDateTime,
    ) {
        if ($endDateTime <= $startDateTime) {
            throw new InvalidArgumentException('End time must be after start time.');
        }
    }

    public function startDateTime(): DateTimeImmutable
    {
        return $this->startDateTime;
    }

    public function endDateTime(): DateTimeImmutable
    {
        return $this->endDateTime;
    }

    public function duration(): int
    {
        return (int) (($this->endDateTime->getTimestamp() - $this->startDateTime->getTimestamp()) / 60);
    }
}
