<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Logging\RequestIdHolder;
use PHPUnit\Framework\TestCase;

final class RequestIdHolderTest extends TestCase
{
    public function testStartsEmpty(): void
    {
        self::assertNull(new RequestIdHolder()->get());
    }

    public function testSetThenGetReturnsTheValue(): void
    {
        $holder = new RequestIdHolder();
        $holder->set('req-42');

        self::assertSame('req-42', $holder->get());
    }

    public function testClearResetsToNull(): void
    {
        $holder = new RequestIdHolder();
        $holder->set('req-42');
        $holder->clear();

        self::assertNull($holder->get());
    }

    public function testSetNullClearsTheValue(): void
    {
        $holder = new RequestIdHolder();
        $holder->set('req-42');
        $holder->set(null);

        self::assertNull($holder->get());
    }
}
