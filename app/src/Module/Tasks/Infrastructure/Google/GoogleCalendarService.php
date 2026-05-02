<?php

declare(strict_types=1);

namespace App\Module\Tasks\Infrastructure\Google;

use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\Port\CalendarServiceInterface;
use App\Module\Tasks\Infrastructure\Persistence\GoogleTokenRepositoryInterface;
use DateTime;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class GoogleCalendarService implements CalendarServiceInterface
{
    public function __construct(
        private Client $client,
        private GoogleTokenRepositoryInterface $tokenRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function createEvent(Task $task): string
    {
        try {
            $calendarService = $this->prepareAuthenticatedClient();
            if (null === $calendarService) {
                return '';
            }

            $event = $this->buildEvent($task);
            $created = $calendarService->events->insert('primary', $event);

            return (string) $created->getId();
        } catch (Throwable $e) {
            $this->logger->warning('Google Calendar createEvent failed', [
                'taskId' => $task->id(),
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    public function updateEvent(Task $task): void
    {
        if (null === $task->googleEventId()) {
            return;
        }

        try {
            $calendarService = $this->prepareAuthenticatedClient();
            if (null === $calendarService) {
                return;
            }

            $event = $this->buildEvent($task);
            $calendarService->events->update('primary', $task->googleEventId(), $event);
        } catch (Throwable $e) {
            $this->logger->warning('Google Calendar updateEvent failed', [
                'taskId' => $task->id(),
                'googleEventId' => $task->googleEventId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleteEvent(string $googleEventId): void
    {
        try {
            $calendarService = $this->prepareAuthenticatedClient();
            if (null === $calendarService) {
                return;
            }

            $calendarService->events->delete('primary', $googleEventId);
        } catch (Throwable $e) {
            $this->logger->warning('Google Calendar deleteEvent failed', [
                'googleEventId' => $googleEventId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function buildEvent(Task $task): Event
    {
        $event = new Event();
        $event->setSummary($task->title()->value());
        $event->setDescription($task->id());

        $start = new EventDateTime();
        $start->setDateTime($task->timeSlot()->startDateTime()->format(DateTime::RFC3339));
        $event->setStart($start);

        $end = new EventDateTime();
        $end->setDateTime($task->timeSlot()->endDateTime()->format(DateTime::RFC3339));
        $event->setEnd($end);

        return $event;
    }

    private function prepareAuthenticatedClient(): ?Calendar
    {
        $tokenData = $this->tokenRepository->get();

        if (null === $tokenData) {
            $this->logger->warning('Google Calendar: no OAuth token configured, skipping calendar sync');

            return null;
        }

        $this->client->setAccessToken($tokenData);

        if ($this->client->isAccessTokenExpired()) {
            $refreshToken = $this->client->getRefreshToken();
            if (null === $refreshToken) {
                $this->logger->warning('Google Calendar: refresh token missing, re-authentication required');

                return null;
            }

            $newToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
            $this->tokenRepository->save($newToken);
            $this->client->setAccessToken($newToken);
        }

        return new Calendar($this->client);
    }
}
