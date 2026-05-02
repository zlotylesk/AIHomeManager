<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Tasks\Infrastructure;

use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\ValueObject\TaskTitle;
use App\Module\Tasks\Domain\ValueObject\TimeSlot;
use App\Module\Tasks\Infrastructure\Google\GoogleCalendarService;
use App\Module\Tasks\Infrastructure\Persistence\GoogleTokenRepositoryInterface;
use DateTime;
use DateTimeImmutable;
use Google\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class GoogleCalendarServiceTest extends TestCase
{
    private Client $client;
    private GoogleTokenRepositoryInterface $tokenRepository;
    private LoggerInterface $logger;
    private GoogleCalendarService $service;

    protected function setUp(): void
    {
        $this->client = $this->createStub(Client::class);
        $this->tokenRepository = $this->createStub(GoogleTokenRepositoryInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);

        $this->service = new GoogleCalendarService(
            $this->client,
            $this->tokenRepository,
            $this->logger,
        );
    }

    public function testCreateEventReturnsEmptyStringWhenNoToken(): void
    {
        $this->tokenRepository->method('get')->willReturn(null);

        $result = $this->service->createEvent($this->makeTask());

        self::assertSame('', $result);
    }

    public function testCreateEventLogsWarningWhenNoToken(): void
    {
        $this->tokenRepository->method('get')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = new GoogleCalendarService($this->client, $this->tokenRepository, $logger);
        $service->createEvent($this->makeTask());
    }

    public function testCreateEventReturnsEmptyStringOnException(): void
    {
        $this->tokenRepository->method('get')->willReturn(['access_token' => 'tok', 'refresh_token' => 'ref']);
        $this->client->method('setAccessToken')->willReturn(null);
        $this->client->method('isAccessTokenExpired')->willThrowException(new RuntimeException('API error'));

        $result = $this->service->createEvent($this->makeTask());

        self::assertSame('', $result);
    }

    public function testUpdateEventDoesNotThrowWhenNoToken(): void
    {
        $this->tokenRepository->method('get')->willReturn(null);
        $task = $this->makeTask(googleEventId: 'event-123');

        $this->service->updateEvent($task);

        $this->addToAssertionCount(1);
    }

    public function testUpdateEventDoesNotThrowOnException(): void
    {
        $this->tokenRepository->method('get')->willReturn(['access_token' => 'tok']);
        $this->client->method('isAccessTokenExpired')->willThrowException(new RuntimeException('API error'));
        $task = $this->makeTask(googleEventId: 'event-123');

        $this->service->updateEvent($task);

        $this->addToAssertionCount(1);
    }

    public function testUpdateEventSkipsWhenNoGoogleEventId(): void
    {
        $tokenRepo = $this->createMock(GoogleTokenRepositoryInterface::class);
        $tokenRepo->expects(self::never())->method('get');

        $service = new GoogleCalendarService($this->client, $tokenRepo, $this->logger);
        $service->updateEvent($this->makeTask());
    }

    public function testDeleteEventDoesNotThrowWhenNoToken(): void
    {
        $this->tokenRepository->method('get')->willReturn(null);

        $this->service->deleteEvent('event-123');

        $this->addToAssertionCount(1);
    }

    public function testDeleteEventDoesNotThrowOnException(): void
    {
        $this->tokenRepository->method('get')->willReturn(['access_token' => 'tok']);
        $this->client->method('isAccessTokenExpired')->willThrowException(new RuntimeException('API error'));

        $this->service->deleteEvent('event-123');

        $this->addToAssertionCount(1);
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
