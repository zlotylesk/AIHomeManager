<?php

declare(strict_types=1);

namespace App\Module\Tasks\Infrastructure\Google;

use App\Module\Tasks\Domain\Entity\Task;
use App\Module\Tasks\Domain\Port\CalendarServiceInterface;
use App\Shared\Security\GoogleAccessTokenProviderInterface;
use DateTime;
use Google\Client;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final readonly class GoogleCalendarService implements CalendarServiceInterface
{
    public function __construct(
        private Client $client,
        private GoogleAccessTokenProviderInterface $accessTokenProvider,
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
        } catch (GoogleServiceException|GuzzleException|InvalidArgumentException $e) {
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
        } catch (GoogleServiceException|GuzzleException|InvalidArgumentException $e) {
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
        } catch (GoogleServiceException|GuzzleException|InvalidArgumentException $e) {
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

    /**
     * Delegates freshness (including refresh-on-expiry) to the shared
     * GoogleAccessTokenProviderInterface port. Its side effect of calling
     * $this->client->setAccessToken() on the injected `google.client`
     * instance is what leaves the client authenticated by the time we build
     * `new Calendar($this->client)` below — provider and service MUST share
     * the same Client instance for this to hold.
     */
    private function prepareAuthenticatedClient(): ?Calendar
    {
        $token = $this->accessTokenProvider->getValidAccessToken();

        if (null === $token) {
            $this->logger->warning('Google Calendar: no valid OAuth token available, skipping calendar sync');

            return null;
        }

        return new Calendar($this->client);
    }
}
