<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring;

use App\Monitoring\Alert;
use App\Monitoring\AlertSeverity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AlertTest extends TestCase
{
    public function testNamespacingPrefixesTheKeyAndKeepsEverythingElse(): void
    {
        $alert = new Alert('mysql', AlertSeverity::CRITICAL, 'mysql is down', 'check the container');

        $namespaced = $alert->namespacedIn('health');

        self::assertSame('health:mysql', $namespaced->key);
        self::assertSame(AlertSeverity::CRITICAL, $namespaced->severity);
        self::assertSame('mysql is down', $namespaced->title);
        self::assertSame('check the container', $namespaced->detail);
    }

    public function testNamespacingLeavesTheOriginalAlone(): void
    {
        $alert = new Alert('mysql', AlertSeverity::CRITICAL, 'mysql is down', '');

        $alert->namespacedIn('health');

        self::assertSame('mysql', $alert->key);
    }

    public function testRejectsAnEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Alert('  ', AlertSeverity::WARNING, 'something', '');
    }

    public function testRejectsAnEmptyTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Alert('disk', AlertSeverity::WARNING, '   ', '');
    }

    public function testCriticalOutranksWarningAndNothingOutranksItself(): void
    {
        self::assertTrue(AlertSeverity::CRITICAL->outranks(AlertSeverity::WARNING));
        self::assertFalse(AlertSeverity::WARNING->outranks(AlertSeverity::CRITICAL));
        self::assertFalse(AlertSeverity::CRITICAL->outranks(AlertSeverity::CRITICAL));
        self::assertFalse(AlertSeverity::WARNING->outranks(AlertSeverity::WARNING));
    }
}
