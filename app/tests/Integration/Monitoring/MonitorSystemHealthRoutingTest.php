<?php

declare(strict_types=1);

namespace App\Tests\Integration\Monitoring;

use App\Application\Scheduled\MonitorSystemHealth;
use App\Module\Notifications\Application\Command\DispatchNotification;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/**
 * The monitoring sweep must stay synchronous.
 *
 * Every other recurring command rides the async transport, and this one must
 * not: a dead async worker is one of the failures being watched for, so routing
 * the sweep there would park the alert about a dead worker in the very queue
 * that worker was meant to drain. It would also make announcing "RabbitMQ is
 * down" depend on RabbitMQ being up.
 *
 * Asked of the senders locator rather than by dispatching, because dispatching
 * this particular message runs the real sweep — probing MySQL, Redis, the
 * broker and the search engine — which is several seconds of live infrastructure
 * to answer a question about a configuration file.
 */
final class MonitorSystemHealthRoutingTest extends KernelTestCase
{
    private SendersLocatorInterface $senders;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->senders = static::getContainer()->get('messenger.senders_locator');
    }

    public function testMonitorSystemHealthIsNotRoutedToAnyTransport(): void
    {
        self::assertSame(
            [],
            $this->transportsFor(new MonitorSystemHealth()),
            'The sweep must run inline in the scheduler worker. Routing it makes the alert about a dead async worker depend on that worker.',
        );
    }

    public function testDispatchNotificationStillGoesToAsync(): void
    {
        self::assertSame(
            ['async'],
            $this->transportsFor(new DispatchNotification('task_due', 'task-1', '2026-08-11')),
            'Counter-proof: this test can tell a routed message from an unrouted one.',
        );
    }

    /** @return list<string> */
    private function transportsFor(object $message): array
    {
        return array_keys(iterator_to_array($this->senders->getSenders(new Envelope($message))));
    }
}
