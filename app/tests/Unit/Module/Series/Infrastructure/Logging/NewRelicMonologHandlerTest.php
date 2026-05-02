<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Series\Infrastructure\Logging;

use App\Module\Series\Infrastructure\Logging\NewRelicMonologHandler;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class NewRelicMonologHandlerTest extends TestCase
{
    public function testConstructsWithoutExceptionWhenExtensionNotAvailable(): void
    {
        $handler = new NewRelicMonologHandler(extensionAvailable: false);

        self::assertInstanceOf(NewRelicMonologHandler::class, $handler);
    }

    public function testDefaultLevelIsDebug(): void
    {
        $handler = new NewRelicMonologHandler(extensionAvailable: false);

        self::assertTrue($handler->isHandling($this->makeRecord(Level::Debug)));
    }

    public function testRespectsConfiguredLevel(): void
    {
        $handler = new NewRelicMonologHandler(level: Level::Warning, extensionAvailable: false);

        self::assertFalse($handler->isHandling($this->makeRecord(Level::Debug)));
        self::assertFalse($handler->isHandling($this->makeRecord(Level::Info)));
        self::assertTrue($handler->isHandling($this->makeRecord(Level::Warning)));
        self::assertTrue($handler->isHandling($this->makeRecord(Level::Error)));
    }

    public function testHandleDoesNotThrowWhenExtensionDisabled(): void
    {
        $handler = new NewRelicMonologHandler(extensionAvailable: false);

        $handler->handle($this->makeRecord(Level::Error, 'Something failed'));

        $this->addToAssertionCount(1);
    }

    public function testHandleDoesNotThrowForAnyLevelWhenExtensionDisabled(): void
    {
        $handler = new NewRelicMonologHandler(extensionAvailable: false);

        foreach ([Level::Debug, Level::Info, Level::Warning, Level::Error, Level::Critical] as $level) {
            $handler->handle($this->makeRecord($level));
        }

        $this->addToAssertionCount(1);
    }

    public function testHandleDoesNotThrowWhenExtensionEnabledButFunctionsUndefined(): void
    {
        // extensionAvailable: true but newrelic_* functions don't exist in test env
        // write() uses function_exists() guards so this must not throw
        $handler = new NewRelicMonologHandler(extensionAvailable: true);

        $handler->handle($this->makeRecord(Level::Error, 'Critical failure'));

        $this->addToAssertionCount(1);
    }

    public function testBubbleDefaultIsTrue(): void
    {
        $handler = new NewRelicMonologHandler(extensionAvailable: false);

        // bubble=true means handle() returns false (does not stop propagation)
        $result = $handler->handle($this->makeRecord(Level::Info));

        self::assertFalse($result);
    }

    public function testBubbleFalseStopsPropagation(): void
    {
        $handler = new NewRelicMonologHandler(bubble: false, extensionAvailable: false);

        $result = $handler->handle($this->makeRecord(Level::Info));

        self::assertTrue($result);
    }

    private function makeRecord(Level $level, string $message = 'Test message'): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'series',
            level: $level,
            message: $message,
            context: [],
            extra: [],
        );
    }
}
