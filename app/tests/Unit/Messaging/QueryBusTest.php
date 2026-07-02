<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messaging;

use App\Messaging\QueryBus;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class QueryBusTest extends TestCase
{
    public function testAskReturnsTheSingleHandlerResult(): void
    {
        $query = new stdClass();
        $result = ['answer' => 42];

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with($query)
            ->willReturn(new Envelope($query, [new HandledStamp($result, 'handler')]));

        self::assertSame($result, new QueryBus($messageBus)->ask($query));
    }

    public function testAskThrowsWhenNoHandlerRan(): void
    {
        $query = new stdClass();

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope($query));

        $this->expectException(LogicException::class);

        new QueryBus($messageBus)->ask($query);
    }
}
