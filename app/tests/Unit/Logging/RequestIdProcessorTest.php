<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\EventListener\RequestIdListener;
use App\Logging\RequestIdHolder;
use App\Logging\RequestIdProcessor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestIdProcessorTest extends TestCase
{
    public function testWebContextStampsTheRequestAttribute(): void
    {
        $stack = new RequestStack();
        $stack->push($this->requestWithId('req-web'));

        $record = new RequestIdProcessor($stack, new RequestIdHolder())($this->record());

        self::assertSame('req-web', $record->extra['request_id']);
    }

    public function testWorkerContextFallsBackToTheHolder(): void
    {
        $holder = new RequestIdHolder();
        $holder->set('req-worker');

        $record = new RequestIdProcessor(new RequestStack(), $holder)($this->record());

        self::assertSame('req-worker', $record->extra['request_id']);
    }

    public function testWorkerContextWithoutHolderValueIsANoOp(): void
    {
        $record = new RequestIdProcessor(new RequestStack(), new RequestIdHolder())($this->record());

        self::assertArrayNotHasKey('request_id', $record->extra);
    }

    public function testWebContextWinsOverTheHolder(): void
    {
        $stack = new RequestStack();
        $stack->push($this->requestWithId('req-web'));
        $holder = new RequestIdHolder();
        $holder->set('req-worker');

        $record = new RequestIdProcessor($stack, $holder)($this->record());

        self::assertSame('req-web', $record->extra['request_id']);
    }

    public function testRequestWithoutTheAttributeFallsBackToTheHolder(): void
    {
        $stack = new RequestStack();
        $stack->push(new Request());
        $holder = new RequestIdHolder();
        $holder->set('req-worker');

        $record = new RequestIdProcessor($stack, $holder)($this->record());

        self::assertSame('req-worker', $record->extra['request_id']);
    }

    public function testExistingExtraFieldsArePreserved(): void
    {
        $holder = new RequestIdHolder();
        $holder->set('req-worker');

        $record = new RequestIdProcessor(new RequestStack(), $holder)(
            $this->record(['channel_hint' => 'series']),
        );

        self::assertSame('series', $record->extra['channel_hint']);
        self::assertSame('req-worker', $record->extra['request_id']);
    }

    private function requestWithId(string $requestId): Request
    {
        $request = new Request();
        $request->attributes->set(RequestIdListener::ATTRIBUTE_NAME, $requestId);

        return $request;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function record(array $extra = []): LogRecord
    {
        return new LogRecord(
            new DateTimeImmutable(),
            'series',
            Level::Info,
            'message',
            [],
            $extra,
        );
    }
}
