<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener;

use App\EventListener\RequestIdListener;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RequestIdListenerTest extends WebTestCase
{
    private const string UUID_V4_REGEX = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    public function testResponseGetsServerGeneratedUuidWhenHeaderMissing(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        $id = $client->getResponse()->headers->get(RequestIdListener::HEADER_NAME);
        self::assertIsString($id);
        self::assertMatchesRegularExpression(self::UUID_V4_REGEX, $id);
    }

    public function testValidInboundIdIsEchoedBack(): void
    {
        $client = static::createClient();
        $customId = 'client-trace.abc-123_XYZ';
        $client->request('GET', '/api/health', [], [], [
            'HTTP_X-Request-ID' => $customId,
        ]);

        self::assertSame($customId, $client->getResponse()->headers->get(RequestIdListener::HEADER_NAME));
    }

    public function testInvalidInboundIdIsReplacedWithServerGeneratedUuid(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health', [], [], [
            'HTTP_X-Request-ID' => "malicious\nlog-inject; DROP TABLE",
        ]);

        $id = $client->getResponse()->headers->get(RequestIdListener::HEADER_NAME);
        self::assertIsString($id);
        self::assertMatchesRegularExpression(self::UUID_V4_REGEX, $id);
    }

    public function testLogRecordsCarryRequestIdInExtra(): void
    {
        $client = static::createClient();

        // Attach a TestHandler so we can inspect the LogRecord the Monolog
        // processor stamps. ApiExceptionListener guarantees an `error` entry
        // for any /api/* throwable, which is the simplest deterministic way
        // to produce a log line inside the request lifecycle.
        /** @var Logger $logger Symfony MonologBundle aliases LoggerInterface to a Monolog Logger. */
        $logger = static::getContainer()->get(LoggerInterface::class);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $customId = 'log-corr-test-42';
        $client->request('GET', '/api/route-that-does-not-exist', [], [], [
            'HTTP_X-Request-ID' => $customId,
            'HTTP_X-API-Key' => 'test-api-key',
        ]);

        self::assertSame($customId, $client->getResponse()->headers->get(RequestIdListener::HEADER_NAME));
        $matched = array_find($testHandler->getRecords(), fn ($record) => ($record->extra['request_id'] ?? null) === $customId);
        self::assertNotNull($matched, 'No log record carried request_id in extra');
    }
}
