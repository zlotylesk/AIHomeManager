<?php

declare(strict_types=1);

namespace App\Tests\Unit\Messaging;

use App\Messaging\CommandBus;
use LogicException;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class CommandBusTest extends TestCase
{
    public function testDispatchIsAFireAndForgetPassthrough(): void
    {
        $command = new stdClass();
        $stamps = [new BusNameStamp('command.bus')];
        $envelope = new Envelope($command, $stamps);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with($command, $stamps)
            ->willReturn($envelope);

        self::assertSame($envelope, new CommandBus($messageBus)->dispatch($command, $stamps));
    }

    public function testDispatchAndReturnReturnsTheHandlerResult(): void
    {
        $command = new stdClass();
        $result = 'new-id';

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope($command, [new HandledStamp($result, 'handler')]));

        self::assertSame($result, new CommandBus($messageBus)->dispatchAndReturn($command));
    }

    public function testDispatchAndReturnThrowsWhenNoHandlerRan(): void
    {
        $command = new stdClass();

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope($command));

        $this->expectException(LogicException::class);

        new CommandBus($messageBus)->dispatchAndReturn($command);
    }
}
