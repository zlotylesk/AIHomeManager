<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Books\Domain\Entity;

use App\Module\Books\Domain\Entity\ReadingSession;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReadingSessionTest extends TestCase
{
    public function testExposesConstructorArguments(): void
    {
        $date = new DateTimeImmutable('2026-04-12 21:30:00');
        $session = new ReadingSession(
            id: 'session-uuid',
            bookId: 'book-uuid',
            date: $date,
            pagesRead: 35,
            notes: 'Skonczyl rozdzial 4',
        );

        self::assertSame('session-uuid', $session->id());
        self::assertSame('book-uuid', $session->bookId());
        self::assertSame($date, $session->date());
        self::assertSame(35, $session->pagesRead());
        self::assertSame('Skonczyl rozdzial 4', $session->notes());
    }

    public function testNotesAreOptional(): void
    {
        $session = new ReadingSession(
            id: 'session-uuid',
            bookId: 'book-uuid',
            date: new DateTimeImmutable('2026-04-12 21:30:00'),
            pagesRead: 35,
        );

        self::assertNull($session->notes());
    }
}
