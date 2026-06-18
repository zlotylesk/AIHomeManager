<?php

declare(strict_types=1);

namespace App\Tests\Integration\Logging;

use Gelf\Message;
use Gelf\Publisher;
use Gelf\Transport\IgnoreErrorTransportWrapper;
use Gelf\Transport\UdpTransport;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Guards HMAI-176: the `series` and `auth` Monolog channels ship over GELF/UDP on the
 * request path, so an unreachable Graylog (DNS failure when the monitoring stack is down)
 * used to bubble a RuntimeException out of the request and 500 core endpoints like
 * /api/series. The transport must stay wrapped in IgnoreErrorTransportWrapper so a dead
 * log sink degrades silently rather than breaking the request.
 *
 * The test env routes both channels to null handlers, so these services are fetched via
 * the public test.* aliases declared in services.yaml (otherwise they would be pruned).
 */
final class GelfTransportResilienceTest extends KernelTestCase
{
    public function testGelfTransportIsWrappedToIgnoreErrors(): void
    {
        self::bootKernel();

        /** @phpstan-ignore symfonyContainer.serviceNotFound */
        $transport = self::getContainer()->get(IgnoreErrorTransportWrapper::class);

        self::assertInstanceOf(
            IgnoreErrorTransportWrapper::class,
            $transport,
            'gelf.transport must stay wrapped — without it a Graylog outage 500s /api/series.',
        );

        $inner = new ReflectionClass($transport)->getProperty('transport')->getValue($transport);
        self::assertInstanceOf(UdpTransport::class, $inner, 'The wrapper must decorate the UDP transport.');
    }

    public function testPublishingWhenGraylogUnreachableDoesNotThrow(): void
    {
        self::bootKernel();

        /** @phpstan-ignore symfonyContainer.serviceNotFound */
        $publisher = self::getContainer()->get(Publisher::class);
        self::assertInstanceOf(Publisher::class, $publisher);

        $message = new Message()
            ->setShortMessage('HMAI-176 resilience probe')
            ->setHost('test');

        try {
            $publisher->publish($message);
        } catch (Throwable $e) {
            self::fail('publish() must not throw when Graylog is unreachable: '.$e->getMessage());
        }

        $this->addToAssertionCount(1);
    }
}
