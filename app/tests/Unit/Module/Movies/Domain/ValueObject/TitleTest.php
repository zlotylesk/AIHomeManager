<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Movies\Domain\ValueObject;

use App\Module\Movies\Domain\ValueObject\Title;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TitleTest extends TestCase
{
    public function testExposesTheGivenValue(): void
    {
        self::assertSame('Blade Runner 2049', new Title('Blade Runner 2049')->value());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        self::assertSame('Arrival', new Title("  Arrival \t")->value());
    }

    public function testThrowsWhenEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Movie title cannot be empty.');

        new Title('');
    }

    public function testThrowsWhenOnlyWhitespace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Movie title cannot be empty.');

        new Title("   \t");
    }

    public function testAcceptsTitleAtMaxLength(): void
    {
        $title = new Title(str_repeat('a', Title::MAX_LENGTH));

        self::assertSame(Title::MAX_LENGTH, mb_strlen($title->value()));
    }

    public function testThrowsWhenLongerThanMaxLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed 255 characters');

        new Title(str_repeat('a', Title::MAX_LENGTH + 1));
    }

    public function testLengthIsMeasuredInCharactersNotBytes(): void
    {
        $title = new Title(str_repeat('ż', Title::MAX_LENGTH));

        self::assertSame(Title::MAX_LENGTH, mb_strlen($title->value()));
    }

    public function testEqualsComparesByValue(): void
    {
        self::assertTrue(new Title('Dune')->equals(new Title('Dune')));
        self::assertTrue(new Title('Dune')->equals(new Title('  Dune  ')));
        self::assertFalse(new Title('Dune')->equals(new Title('Dune: Part Two')));
    }
}
