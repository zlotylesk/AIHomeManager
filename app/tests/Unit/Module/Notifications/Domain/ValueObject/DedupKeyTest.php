<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notifications\Domain\ValueObject;

use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\ValueObject\DedupKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DedupKeyTest extends TestCase
{
    public function testBuildsTheOccurrenceFromTypeSubjectAndWindow(): void
    {
        $key = DedupKey::forOccurrence(NotificationType::TASK_DUE, 'task-42', '2026-07-16');

        self::assertSame('task_due:task-42:2026-07-16', $key->occurrence());
    }

    public function testQualifiesTheOccurrencePerChannel(): void
    {
        $key = DedupKey::forOccurrence(NotificationType::TASK_DUE, 'task-42', '2026-07-16');

        self::assertSame('task_due:task-42:2026-07-16:email', $key->forChannel(Channel::EMAIL));
        self::assertNotSame(
            $key->forChannel(Channel::EMAIL),
            $key->forChannel(Channel::PUSH),
            'one occurrence is one notification per channel, each deduplicated on its own',
        );
    }

    public function testTheSameOccurrenceSeenTwiceProducesTheSameKey(): void
    {
        $first = DedupKey::forOccurrence(NotificationType::TASK_DUE, 'task-42', '2026-07-16');
        $second = DedupKey::forOccurrence(NotificationType::TASK_DUE, 'task-42', '2026-07-16');

        self::assertTrue($first->equals($second));
        self::assertSame($first->forChannel(Channel::PUSH), $second->forChannel(Channel::PUSH));
    }

    public function testANewWindowIsANewOccurrence(): void
    {
        $today = DedupKey::forOccurrence(NotificationType::TASK_DUE, 'task-42', '2026-07-16');
        $tomorrow = DedupKey::forOccurrence(NotificationType::TASK_DUE, 'task-42', '2026-07-17');

        self::assertFalse($today->equals($tomorrow));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $key = DedupKey::forOccurrence(NotificationType::DAILY_DIGEST, '  user  ', ' 2026-07-16 ');

        self::assertSame('daily_digest:user:2026-07-16', $key->occurrence());
    }

    public function testRejectsAnEmptySubject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DedupKey::forOccurrence(NotificationType::TASK_DUE, '   ', '2026-07-16');
    }

    public function testRejectsAnEmptyWindow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DedupKey::forOccurrence(NotificationType::TASK_DUE, 'task-42', '');
    }

    public function testRejectsASeparatorInsideAPartSoKeysCannotCollide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DedupKey::forOccurrence(NotificationType::TASK_DUE, 'task:42', '2026-07-16');
    }
}
