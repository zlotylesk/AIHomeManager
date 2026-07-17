<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A recurring daily window in which notifications must not be delivered,
 * expressed as a `HH:MM` start/end pair in the user's local wall-clock time.
 *
 * The window is start-inclusive and end-exclusive, and may wrap around midnight
 * (22:00–07:00 is a single overnight window, not two). Start and end must
 * differ — an empty (or all-day) window would be ambiguous rather than useful.
 *
 * The VO only answers whether an instant falls inside the window; the policy
 * that decides what to do about it belongs to the dispatch engine.
 */
final readonly class QuietHours
{
    private const string TIME_PATTERN = '/^([01]\d|2[0-3]):([0-5]\d)$/';

    private function __construct(
        private int $startMinute,
        private int $endMinute,
    ) {
    }

    /**
     * @param string $start inclusive start of the window, `HH:MM` (00:00–23:59)
     * @param string $end   exclusive end of the window, `HH:MM` (00:00–23:59)
     */
    public static function fromTimes(string $start, string $end): self
    {
        $startMinute = self::toMinuteOfDay($start);
        $endMinute = self::toMinuteOfDay($end);

        if ($startMinute === $endMinute) {
            throw new InvalidArgumentException('Quiet hours start and end must differ.');
        }

        return new self($startMinute, $endMinute);
    }

    public function start(): string
    {
        return self::toTime($this->startMinute);
    }

    public function end(): string
    {
        return self::toTime($this->endMinute);
    }

    /**
     * Whether the window wraps around midnight (e.g. 22:00–07:00).
     */
    public function isOvernight(): bool
    {
        return $this->startMinute > $this->endMinute;
    }

    /**
     * Whether the given instant falls inside the window (start-inclusive,
     * end-exclusive), reading its local wall-clock time.
     */
    public function covers(DateTimeImmutable $at): bool
    {
        $minute = (int) $at->format('G') * 60 + (int) $at->format('i');

        if ($this->isOvernight()) {
            return $minute >= $this->startMinute || $minute < $this->endMinute;
        }

        return $minute >= $this->startMinute && $minute < $this->endMinute;
    }

    public function equals(self $other): bool
    {
        return $this->startMinute === $other->startMinute
            && $this->endMinute === $other->endMinute;
    }

    private static function toMinuteOfDay(string $time): int
    {
        if (1 !== preg_match(self::TIME_PATTERN, $time, $matches)) {
            throw new InvalidArgumentException(sprintf('Quiet hours time must be in HH:MM format, "%s" given.', $time));
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }

    private static function toTime(int $minuteOfDay): string
    {
        return sprintf('%02d:%02d', intdiv($minuteOfDay, 60), $minuteOfDay % 60);
    }
}
