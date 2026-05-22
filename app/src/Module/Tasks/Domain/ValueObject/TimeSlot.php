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

    public function equals(self $other): bool
    {
        // Compare by timestamp (UTC seconds) rather than DateTimeImmutable
        // equality so two slots constructed in different timezones but
        // representing the same instant compare equal — what the domain
        // actually cares about is "the same moment in time."
        return $this->startDateTime->getTimestamp() === $other->startDateTime->getTimestamp()
            && $this->endDateTime->getTimestamp() === $other->endDateTime->getTimestamp();
    }
}
