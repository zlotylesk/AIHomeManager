<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Domain\ValueObject;

use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use PHPUnit\Framework\TestCase;

final class TaskTitleTest extends TestCase
{
    public function testCreatesWithValidValue(): void
    {
        $title = new TaskTitle('Write unit tests');

        self::assertSame('Write unit tests', $title->value());
    }

    public function testThrowsWhenValueIsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TaskTitle('');
    }

    public function testThrowsWhenValueIsOnlyWhitespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TaskTitle('   ');
    }

    public function testThrowsWhenValueExceeds255Characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TaskTitle(str_repeat('a', 256));
    }

    public function testAcceptsValueOfExactly255Characters(): void
    {
        $title = new TaskTitle(str_repeat('a', 255));

        self::assertSame(255, mb_strlen($title->value()));
    }
}
