<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Books\Domain\ValueObject;

use App\Module\Books\Domain\ValueObject\ISBN;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ISBNTest extends TestCase
{
    public function testValidIsbn10(): void
    {
        $isbn = new ISBN('0306406152');

        self::assertSame('0306406152', $isbn->value());
    }

    public function testValidIsbn10WithX(): void
    {
        $isbn = new ISBN('080442957X');

        self::assertSame('080442957X', $isbn->value());
    }

    public function testValidIsbn10WithHyphens(): void
    {
        $isbn = new ISBN('0-306-40615-2');

        self::assertSame('0306406152', $isbn->value());
    }

    public function testValidIsbn13(): void
    {
        $isbn = new ISBN('9780306406157');

        self::assertSame('9780306406157', $isbn->value());
    }

    public function testValidIsbn13WithHyphens(): void
    {
        $isbn = new ISBN('978-0-306-40615-7');

        self::assertSame('9780306406157', $isbn->value());
    }

    public function testThrowsForInvalidIsbn10Checksum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ISBN('0306406153');
    }

    public function testThrowsForInvalidIsbn13Checksum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ISBN('9780306406158');
    }

    public function testThrowsForTooShortValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ISBN('123456789');
    }

    public function testThrowsForTooLongValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ISBN('97803064061570');
    }

    public function testThrowsForNonNumericCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ISBN('030640615A');
    }

    public function testIsbn10LowercaseXIsNormalized(): void
    {
        $isbn = new ISBN('080442957x');

        self::assertSame('080442957X', $isbn->value());
    }
}
