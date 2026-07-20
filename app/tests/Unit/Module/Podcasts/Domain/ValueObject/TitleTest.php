<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Domain\ValueObject;

use App\Module\Podcasts\Domain\ValueObject\Title;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TitleTest extends TestCase
{
    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('Darknet Diaries', new Title('  Darknet Diaries  ')->value());
    }

    public function testRejectsEmptyValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Title must not be empty.');

        new Title('');
    }

    public function testRejectsWhitespaceOnlyValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Title("  \t ");
    }

    public function testAcceptsExactlyMaxLength(): void
    {
        self::assertSame(500, mb_strlen(new Title(str_repeat('a', 500))->value()));
    }

    public function testRejectsValueBeyondMaxLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not exceed 500 characters');

        new Title(str_repeat('a', 501));
    }

    /**
     * The cap is measured in characters, not bytes — a 500-character multibyte
     * title is legal even though it is far more than 500 bytes.
     */
    public function testMeasuresLengthInCharactersNotBytes(): void
    {
        $title = new Title(str_repeat('ł', 500));

        self::assertSame(500, mb_strlen($title->value()));
    }

    public function testEqualsComparesByValue(): void
    {
        self::assertTrue(new Title('Radio Naukowe')->equals(new Title('Radio Naukowe')));
        self::assertFalse(new Title('Radio Naukowe')->equals(new Title('Raport o stanie świata')));
    }

    public function testEqualsIsCaseSensitive(): void
    {
        self::assertFalse(new Title('Serial Killers')->equals(new Title('serial killers')));
    }
}
