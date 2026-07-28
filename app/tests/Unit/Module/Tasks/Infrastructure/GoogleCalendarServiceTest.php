<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Infrastructure;

use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use App\Module\Tasks\Infrastructure\Google\GoogleCalendarService;
use App\Shared\Security\GoogleAccessTokenProviderInterface;
use DateTime;
use DateTimeImmutable;
use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TypeError;

/**
 * Refresh-on-expiry itself is no longer this service's concern (HMAI-399) —
 * it is delegated to GoogleAccessTokenProviderInterface, whose own behavior
 * (refresh success/failure, missing refresh token, warnings) is pinned by
 * GoogleAccessTokenProviderTest. What stays here is: no-token / provider
 * failure handling around each Calendar operation, and buildEvent() mapping.
 */
final class GoogleCalendarServiceTest extends TestCase
{
    private Client&Stub $client;
    private GoogleAccessTokenProviderInterface&Stub $accessTokenProvider;
    private LoggerInterface $logger;
    private GoogleCalendarService $service;

    protected function setUp(): void
    {
        $this->client = $this->createStub(Client::class);
        $this->accessTokenProvider = $this->createStub(GoogleAccessTokenProviderInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);

        $this->service = new GoogleCalendarService(
            $this->client,
            $this->accessTokenProvider,
            $this->logger,
        );
    }

    public function testCreateEventReturnsEmptyStringWhenNoToken(): void
    {
        $this->accessTokenProvider->method('getValidAccessToken')->willReturn(null);

        $result = $this->service->createEvent($this->makeTask());

        self::assertSame('', $result);
    }

    public function testCreateEventLogsWarningWhenNoToken(): void
    {
        $this->accessTokenProvider->method('getValidAccessToken')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = new GoogleCalendarService($this->client, $this->accessTokenProvider, $logger);
        $service->createEvent($this->makeTask());
    }

    public function testCreateEventReturnsEmptyStringOnGoogleApiException(): void
    {
        $this->accessTokenProvider->method('getValidAccessToken')->willThrowException(new GoogleServiceException('API error'));

        $result = $this->service->createEvent($this->makeTask());

        self::assertSame('', $result);
    }

    public function testCreateEventPropagatesProgrammerErrors(): void
    {
        $this->accessTokenProvider->method('getValidAccessToken')->willThrowException(new TypeError('bug'));

        $this->expectException(TypeError::class);
        $this->service->createEvent($this->makeTask());
    }

    public function testUpdateEventDoesNotThrowWhenNoToken(): void
    {
        $this->accessTokenProvider->method('getValidAccessToken')->willReturn(null);
        $task = $this->makeTask(googleEventId: 'event-123');

        $this->service->updateEvent($task);

        $this->addToAssertionCount(1);
    }

    public function testUpdateEventDoesNotThrowOnGoogleApiException(): void
    {
        $this->accessTokenProvider->method('getValidAccessToken')->willThrowException(new GoogleServiceException('API error'));
        $task = $this->makeTask(googleEventId: 'event-123');

        $this->service->updateEvent($task);

        $this->addToAssertionCount(1);
    }

    public function testUpdateEventPropagatesProgrammerErrors(): void
    {
        $this->accessTokenProvider->method('getValidAccessToken')->willThrowException(new TypeError('bug'));
        $task = $this->makeTask(googleEventId: 'event-123');

        $this->expectException(TypeError::class);
        $this->service->updateEvent($task);
    }

    public function testUpdateEventSkipsWhenNoGoogleEventId(): void
    {
        $provider = $this->createMock(GoogleAccessTokenProviderInterface::class);
        $provider->expects(self::never())->method('getValidAccessToken');

        $service = new GoogleCalendarService($this->client, $provider, $this->logger);
        $service->updateEvent($this->makeTask());
    }

    public function testDeleteEventDoesNotThrowWhenNoToken(): void
    {
        $this->accessTokenProvider->method('getValidAccessToken')->willReturn(null);

        $this->service->deleteEvent('event-123');

        $this->addToAssertionCount(1);
    }

    public function testDeleteEventDoesNotThrowOnGoogleApiException(): void
    {
        $this->accessTokenProvider->method('getValidAccessToken')->willThrowException(new GoogleServiceException('API error'));

        $this->service->deleteEvent('event-123');

        $this->addToAssertionCount(1);
    }

    public function testDeleteEventPropagatesProgrammerErrors(): void
    {
        $this->accessTokenProvider->method('getValidAccessToken')->willThrowException(new TypeError('bug'));

        $this->expectException(TypeError::class);
        $this->service->deleteEvent('event-123');
    }

    public function testBuildEventMapsTitleAndId(): void
    {
        $task = $this->makeTask();

        $event = $this->service->buildEvent($task);

        self::assertSame('Test Task', $event->getSummary());
        self::assertSame($task->id(), $event->getDescription());
    }

    public function testBuildEventMapsTimeSlot(): void
    {
        $start = new DateTimeImmutable('2024-06-15 10:00:00');
        $end = new DateTimeImmutable('2024-06-15 11:00:00');
        $task = $this->makeTask(start: $start, end: $end);

        $event = $this->service->buildEvent($task);

        self::assertSame(
            $start->format(DateTime::RFC3339),
            $event->getStart()->getDateTime()
        );
        self::assertSame(
            $end->format(DateTime::RFC3339),
            $event->getEnd()->getDateTime()
        );
    }

    private function makeTask(
        ?string $googleEventId = null,
        ?DateTimeImmutable $start = null,
        ?DateTimeImmutable $end = null,
    ): Task {
        $start ??= new DateTimeImmutable('+1 hour');
        $end ??= new DateTimeImmutable('+2 hours');

        return new Task(
            id: 'task-test-uuid',
            title: new TaskTitle('Test Task'),
            timeSlot: new TimeSlot($start, $end),
            googleEventId: $googleEventId,
        );
    }
}
