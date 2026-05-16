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
use Google\Service\Exception as GoogleServiceException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TypeError;

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

    public function testCreateEventReturnsEmptyStringOnGoogleApiException(): void
    {
        $this->tokenRepository->method('get')->willReturn(['access_token' => 'tok', 'refresh_token' => 'ref']);
        $this->client->method('setAccessToken')->willReturn(null);
        $this->client->method('isAccessTokenExpired')->willThrowException(new GoogleServiceException('API error'));

        $result = $this->service->createEvent($this->makeTask());

        self::assertSame('', $result);
    }

    public function testCreateEventPropagatesProgrammerErrors(): void
    {
        $this->tokenRepository->method('get')->willReturn(['access_token' => 'tok']);
        $this->client->method('setAccessToken')->willReturn(null);
        $this->client->method('isAccessTokenExpired')->willThrowException(new TypeError('bug'));

        $this->expectException(TypeError::class);
        $this->service->createEvent($this->makeTask());
    }

    public function testUpdateEventDoesNotThrowWhenNoToken(): void
    {
        $this->tokenRepository->method('get')->willReturn(null);
        $task = $this->makeTask(googleEventId: 'event-123');

        $this->service->updateEvent($task);

        $this->addToAssertionCount(1);
    }

    public function testUpdateEventDoesNotThrowOnGoogleApiException(): void
    {
        $this->tokenRepository->method('get')->willReturn(['access_token' => 'tok']);
        $this->client->method('isAccessTokenExpired')->willThrowException(new GoogleServiceException('API error'));
        $task = $this->makeTask(googleEventId: 'event-123');

        $this->service->updateEvent($task);

        $this->addToAssertionCount(1);
    }

    public function testUpdateEventPropagatesProgrammerErrors(): void
    {
        $this->tokenRepository->method('get')->willReturn(['access_token' => 'tok']);
        $this->client->method('isAccessTokenExpired')->willThrowException(new TypeError('bug'));
        $task = $this->makeTask(googleEventId: 'event-123');

        $this->expectException(TypeError::class);
        $this->service->updateEvent($task);
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

    public function testDeleteEventDoesNotThrowOnGoogleApiException(): void
    {
        $this->tokenRepository->method('get')->willReturn(['access_token' => 'tok']);
        $this->client->method('isAccessTokenExpired')->willThrowException(new GoogleServiceException('API error'));

        $this->service->deleteEvent('event-123');

        $this->addToAssertionCount(1);
    }

    public function testDeleteEventPropagatesProgrammerErrors(): void
    {
        $this->tokenRepository->method('get')->willReturn(['access_token' => 'tok']);
        $this->client->method('isAccessTokenExpired')->willThrowException(new TypeError('bug'));

        $this->expectException(TypeError::class);
        $this->service->deleteEvent('event-123');
    }

    public function testCreateEventReturnsEmptyStringWhenTokenRefreshFails(): void
    {
        $service = new GoogleCalendarService(
            $this->clientWithFailedRefresh(),
            $this->tokenRepoWithExpiredToken(),
            $this->logger,
        );

        $result = $service->createEvent($this->makeTask());

        self::assertSame('', $result);
    }

    public function testCreateEventRefreshesExpiredTokenAndPersistsNewToken(): void
    {
        $newToken = ['access_token' => 'new-tok', 'expires_in' => 3600];

        $tokenRepo = $this->createMock(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(['access_token' => 'expired', 'refresh_token' => 'old-refresh']);
        $tokenRepo->expects(self::once())->method('save')->with($newToken);

        $client = $this->createMock(Client::class);
        $client->method('isAccessTokenExpired')->willReturn(true);
        $client->method('getRefreshToken')->willReturn('old-refresh');
        $client->expects(self::once())
            ->method('fetchAccessTokenWithRefreshToken')
            ->with('old-refresh')
            ->willReturn($newToken);
        // Google SDK Resource layer calls Client::getLogger() before issuing the request.
        $client->method('getLogger')->willReturn(new NullLogger());
        // Throws on subsequent API call to avoid hitting the real Google Calendar HTTP.
        $client->method('execute')->willThrowException(new GoogleServiceException('no-network'));

        $service = new GoogleCalendarService($client, $tokenRepo, $this->logger);
        $service->createEvent($this->makeTask());
    }

    public function testCreateEventReturnsEmptyStringWhenRefreshTokenMissing(): void
    {
        $tokenRepo = $this->createMock(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(['access_token' => 'expired']);
        $tokenRepo->expects(self::never())->method('save');

        $client = $this->createStub(Client::class);
        $client->method('isAccessTokenExpired')->willReturn(true);
        $client->method('getRefreshToken')->willReturn(null);

        $service = new GoogleCalendarService($client, $tokenRepo, $this->logger);

        self::assertSame('', $service->createEvent($this->makeTask()));
    }

    public function testCreateEventLogsWarningWhenRefreshTokenMissing(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('isAccessTokenExpired')->willReturn(true);
        $client->method('getRefreshToken')->willReturn(null);

        $tokenRepo = $this->createStub(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(['access_token' => 'expired']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('refresh token missing')
        );

        $service = new GoogleCalendarService($client, $tokenRepo, $logger);
        $service->createEvent($this->makeTask());
    }

    public function testCreateEventDoesNotSaveCorruptedTokenWhenRefreshFails(): void
    {
        $tokenRepo = $this->createMock(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(['access_token' => 'expired', 'refresh_token' => 'revoked-refresh']);
        $tokenRepo->expects(self::never())->method('save');

        $service = new GoogleCalendarService($this->clientWithFailedRefresh(), $tokenRepo, $this->logger);
        $service->createEvent($this->makeTask());
    }

    public function testCreateEventLogsWarningWhenTokenRefreshFails(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Google Calendar: token refresh failed, re-authentication required',
            self::callback(static fn (array $ctx) => 'invalid_grant' === $ctx['error'])
        );

        $service = new GoogleCalendarService(
            $this->clientWithFailedRefresh(),
            $this->tokenRepoWithExpiredToken(),
            $logger,
        );
        $service->createEvent($this->makeTask());
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

    /**
     * Pre-configured Google Client stub for the "refresh-token-revoked" scenario:
     * token is reported expired and fetchAccessTokenWithRefreshToken returns
     * Google's standard error-shaped response instead of a fresh token.
     */
    private function clientWithFailedRefresh(): Client
    {
        $client = $this->createStub(Client::class);
        $client->method('isAccessTokenExpired')->willReturn(true);
        $client->method('getRefreshToken')->willReturn('revoked-refresh');
        $client->method('fetchAccessTokenWithRefreshToken')->willReturn([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ]);

        return $client;
    }

    private function tokenRepoWithExpiredToken(): GoogleTokenRepositoryInterface
    {
        $tokenRepo = $this->createStub(GoogleTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(['access_token' => 'expired', 'refresh_token' => 'revoked-refresh']);

        return $tokenRepo;
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
