<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Series\Domain\Enum\SeriesStatus;
use App\Module\Series\Infrastructure\Persistence\Doctrine\Type\SeriesStatusType;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;

final class SeriesStatusTypeTest extends TestCase
{
    private SeriesStatusType $type;

    private MySQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new SeriesStatusType();
        $this->platform = new MySQLPlatform();
    }

    public function testGetNameReturnsRegisteredTypeName(): void
    {
        self::assertSame('series_status', $this->type->getName());
        self::assertSame(SeriesStatusType::NAME, $this->type->getName());
    }

    public function testConvertsNullDatabaseValueToNullPhpValue(): void
    {
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testConvertsStringDatabaseValueToEnum(): void
    {
        self::assertSame(SeriesStatus::ONGOING, $this->type->convertToPHPValue('ongoing', $this->platform));
        self::assertSame(SeriesStatus::ENDED, $this->type->convertToPHPValue('ended', $this->platform));
    }

    public function testConvertsNullPhpValueToNullDatabaseValue(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
    }

    public function testConvertsEnumToDatabaseStringValue(): void
    {
        self::assertSame('ongoing', $this->type->convertToDatabaseValue(SeriesStatus::ONGOING, $this->platform));
        self::assertSame('ended', $this->type->convertToDatabaseValue(SeriesStatus::ENDED, $this->platform));
    }

    public function testConvertsStringPhpValueToDatabaseStringFallback(): void
    {
        self::assertSame('ongoing', $this->type->convertToDatabaseValue('ongoing', $this->platform));
    }

    public function testRoundTripPreservesEnum(): void
    {
        foreach (SeriesStatus::cases() as $status) {
            $stored = $this->type->convertToDatabaseValue($status, $this->platform);

            self::assertSame($status, $this->type->convertToPHPValue($stored, $this->platform));
        }
    }

    public function testSqlDeclarationAppliesLengthTwenty(): void
    {
        $sql = $this->type->getSQLDeclaration(['name' => 'status'], $this->platform);

        self::assertStringContainsStringIgnoringCase('VARCHAR(20)', $sql);
    }
}
