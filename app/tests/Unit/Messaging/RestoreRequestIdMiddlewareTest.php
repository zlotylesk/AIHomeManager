<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messaging;

use App\Logging\RequestIdHolder;
use App\Messaging\RequestIdStamp;
use App\Messaging\RestoreRequestIdMiddleware;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class RestoreRequestIdMiddlewareTest extends TestCase
{
    public function testExposesTheStampIdWhileHandlingAndClearsItAfter(): void
    {
        $holder = new RequestIdHolder();
        $middleware = new RestoreRequestIdMiddleware($holder);
        $envelope = new Envelope(new stdClass(), [new RequestIdStamp('req-worker-1')]);

        $seenDuringHandle = 'unset';
        $middleware->handle($envelope, $this->stackRunning(function () use ($holder, &$seenDuringHandle): void {
            $seenDuringHandle = $holder->get();
        }));

        self::assertSame('req-worker-1', $seenDuringHandle, 'the holder carries the id while the handler runs');
        self::assertNull($holder->get(), 'the holder is cleared after the message is handled');
    }

    public function testLeavesTheHolderEmptyWithoutAStamp(): void
    {
        $holder = new RequestIdHolder();
        $middleware = new RestoreRequestIdMiddleware($holder);

        $seenDuringHandle = 'unset';
        $middleware->handle(new Envelope(new stdClass()), $this->stackRunning(function () use ($holder, &$seenDuringHandle): void {
            $seenDuringHandle = $holder->get();
        }));

        self::assertNull($seenDuringHandle, 'a message without a stamp does not set the holder');
        self::assertNull($holder->get());
    }

    public function testAStamplessMessageDoesNotClearAnOuterId(): void
    {
        // Nested sync dispatch inside a worker handler: the outer frame already set
        // the id; a stampless inner message must not wipe it in its finally.
        $holder = new RequestIdHolder();
        $holder->set('outer-id');
        $middleware = new RestoreRequestIdMiddleware($holder);

        $middleware->handle(new Envelope(new stdClass()), $this->stackRunning(static function (): void {}));

        self::assertSame('outer-id', $holder->get());
    }

    public function testClearsTheHolderEvenWhenHandlingThrows(): void
    {
        $holder = new RequestIdHolder();
        $middleware = new RestoreRequestIdMiddleware($holder);
        $envelope = new Envelope(new stdClass(), [new RequestIdStamp('req-boom')]);

        try {
            $middleware->handle($envelope, $this->stackRunning(static function (): void {
                throw new RuntimeException('handler failed');
            }));
            self::fail('the handler exception must propagate');
        } catch (RuntimeException) {
            // expected — the finally still runs
        }

        self::assertNull($holder->get(), 'a failed handler still clears the holder');
    }

    private function stackRunning(Closure $onHandle): StackInterface
    {
        return new readonly class($onHandle) implements StackInterface {
            public function __construct(private Closure $onHandle)
            {
            }

            public function next(): MiddlewareInterface
            {
                return new readonly class($this->onHandle) implements MiddlewareInterface {
                    public function __construct(private Closure $onHandle)
                    {
                    }

                    public function handle(Envelope $envelope, StackInterface $stack): Envelope
                    {
                        ($this->onHandle)();

                        return $envelope;
                    }
                };
            }
        };
    }
}
