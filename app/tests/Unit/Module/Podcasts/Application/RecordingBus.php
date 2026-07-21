<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Application;

use App\Module\Podcasts\Application\Command\LogPodcastListeningSession;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Records what the sweep handed on, and can be told to fail for one episode so
 * the fault-isolation case is exercised the way Messenger really behaves — a
 * handler exception arrives wrapped in HandlerFailedException.
 */
final class RecordingBus implements MessageBusInterface
{
    /** @var list<LogPodcastListeningSession> */
    public array $dispatched = [];

    public function __construct(private readonly ?string $failOn = null)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        \assert($message instanceof LogPodcastListeningSession);

        if (null !== $this->failOn && $message->listened->episodeExternalId === $this->failOn) {
            throw new HandlerFailedException(new Envelope($message), [new RuntimeException('Title must not exceed 500 characters.')]);
        }

        $this->dispatched[] = $message;

        return new Envelope($message);
    }
}
