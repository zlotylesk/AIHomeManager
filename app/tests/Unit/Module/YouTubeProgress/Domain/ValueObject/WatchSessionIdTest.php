<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\YouTubeProgress\Domain\ValueObject;

use App\Module\YouTubeProgress\Domain\ValueObject\WatchSessionId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WatchSessionIdTest extends TestCase
{
    public function testGenerateProducesValidUuidV4(): void
    {
        $id = WatchSessionId::generate();

        // Round-trip via fromString to confirm the generated payload passes
        // our strict validator — guards against the day Symfony's Uuid::v4()
        // ever changes shape.
        $rehydrated = WatchSessionId::fromString($id->value);

        self::assertSame($id->value, $rehydrated->value);
    }

    public function testFromStringAcceptsValidUuid(): void
    {
        $valid = '550e8400-e29b-41d4-a716-446655440000';

        $id = WatchSessionId::fromString($valid);

        self::assertSame($valid, $id->value);
    }

    public function testFromStringRejectsNonUuidString(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WatchSessionId::fromString('not-a-uuid');
    }

    public function testFromStringRejectsUuidWithWrongVersion(): void
    {
        // RFC 4122 UUID with version 1 (timestamp-based) — must be rejected
        // because we mint v4 only.
        $this->expectException(InvalidArgumentException::class);

        WatchSessionId::fromString('12345678-1234-1234-1234-123456789012');
    }

    public function testFromStringRejectsUppercase(): void
    {
        // Symfony's toRfc4122() emits lowercase, so we never need to accept
        // uppercase. Locking it down keeps comparisons trivially case-sensitive.
        $this->expectException(InvalidArgumentException::class);

        WatchSessionId::fromString('550E8400-E29B-41D4-A716-446655440000');
    }

    public function testEqualsReturnsTrueForSameValue(): void
    {
        $value = '550e8400-e29b-41d4-a716-446655440000';

        self::assertTrue(WatchSessionId::fromString($value)->equals(WatchSessionId::fromString($value)));
    }

    public function testEqualsReturnsFalseForDifferentValue(): void
    {
        $a = WatchSessionId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $b = WatchSessionId::fromString('6ba7b810-9dad-41d1-80b4-00c04fd430c8');

        self::assertFalse($a->equals($b));
    }

    public function testToStringReturnsValue(): void
    {
        $value = '550e8400-e29b-41d4-a716-446655440000';

        self::assertSame($value, WatchSessionId::fromString($value)->toString());
    }
}
