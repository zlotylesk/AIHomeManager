<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tasks;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TasksExportApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement('TRUNCATE TABLE tasks');
    }

    public function testExportReturnsCsvWithBomAndAttachmentHeader(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->insert('tasks', [
            'id' => 'task0001-0000-0000-0000-000000000000',
            'title' => 'Write tests',
            'status' => 'completed',
            'time_start' => '2025-01-10 08:00:00',
            'time_end' => '2025-01-10 09:30:00',
            'google_event_id' => 'google-evt-42',
        ]);

        $this->client->request('GET', '/api/tasks/export');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=tasks.csv', $response->headers->get('Content-Disposition'));

        $body = (string) $response->getContent();

        self::assertSame("\xEF\xBB\xBF", substr($body, 0, 3));
        self::assertStringContainsString('title,startTime,endTime,durationMinutes,googleEventId', $body);
        self::assertStringContainsString('"Write tests","2025-01-10 08:00:00","2025-01-10 09:30:00",90,google-evt-42', $body);
    }

    public function testExportExcludesPendingAndCancelledTasks(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->insert('tasks', [
            'id' => 'a0000001-0000-0000-0000-000000000000',
            'title' => 'Done task',
            'status' => 'completed',
            'time_start' => '2025-01-10 08:00:00',
            'time_end' => '2025-01-10 09:00:00',
            'google_event_id' => null,
        ]);
        $conn->insert('tasks', [
            'id' => 'a0000002-0000-0000-0000-000000000000',
            'title' => 'Pending task',
            'status' => 'pending',
            'time_start' => '2025-01-10 10:00:00',
            'time_end' => '2025-01-10 11:00:00',
            'google_event_id' => null,
        ]);

        $this->client->request('GET', '/api/tasks/export');

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Done task', $body);
        self::assertStringNotContainsString('Pending task', $body);
    }

    public function testExportFiltersByDateRange(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->insert('tasks', [
            'id' => 'a0000001-0000-0000-0000-000000000000',
            'title' => 'January task',
            'status' => 'completed',
            'time_start' => '2025-01-15 08:00:00',
            'time_end' => '2025-01-15 09:00:00',
            'google_event_id' => null,
        ]);
        $conn->insert('tasks', [
            'id' => 'a0000002-0000-0000-0000-000000000000',
            'title' => 'March task',
            'status' => 'completed',
            'time_start' => '2025-03-15 08:00:00',
            'time_end' => '2025-03-15 09:00:00',
            'google_event_id' => null,
        ]);

        $this->client->request('GET', '/api/tasks/export?from=2025-01-01&to=2025-01-31');

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('January task', $body);
        self::assertStringNotContainsString('March task', $body);
    }

    public function testExportRejectsInvalidDateFormatWith422(): void
    {
        $this->client->request('GET', '/api/tasks/export?from=not-a-date');

        self::assertResponseStatusCodeSame(422);
    }

    public function testExportReturnsHeaderOnlyForEmptyCollection(): void
    {
        $this->client->request('GET', '/api/tasks/export');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertSame("\xEF\xBB\xBFtitle,startTime,endTime,durationMinutes,googleEventId\n", $body);
    }

    public function testPdfExportContainsPdfMagicBytes(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->insert('tasks', [
            'id' => 'task0002-0000-0000-0000-000000000000',
            'title' => 'PDF test task',
            'status' => 'completed',
            'time_start' => '2025-01-10 08:00:00',
            'time_end' => '2025-01-10 09:00:00',
            'google_event_id' => null,
        ]);

        $this->client->request('GET', '/api/tasks/export?format=pdf');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=tasks.pdf', $response->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function testExportRejectsInvalidFormatWith422(): void
    {
        $this->client->request('GET', '/api/tasks/export?format=xml');

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * HMAI-400: `to` is documented as an inclusive upper bound on the task
     * start. A task starting mid-day on the `to` date itself used to be
     * silently excluded from the export because a bare date parses to
     * midnight; this pins that the whole day named by `to` is now covered.
     */
    public function testExportIncludesTaskStartingOnLastDayOfRange(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->insert('tasks', [
            'id' => 'c0000001-0000-0000-0000-000000000000',
            'title' => 'Last day export task',
            'status' => 'completed',
            'time_start' => '2025-01-31 22:00:00',
            'time_end' => '2025-01-31 22:30:00',
            'google_event_id' => null,
        ]);

        $this->client->request('GET', '/api/tasks/export?from=2025-01-31&to=2025-01-31&format=csv');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Last day export task', $body);
    }

    /**
     * HMAI-400: the inclusive-day widening must stop exactly at the next
     * day's midnight — a task starting at `D+1 00:00:00` is one range-width
     * over and must stay excluded, otherwise the fix would have just moved
     * the off-by-one bug forward by a day.
     */
    public function testExportExcludesTaskStartingAfterUpperBoundDay(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->insert('tasks', [
            'id' => 'c0000002-0000-0000-0000-000000000000',
            'title' => 'February overflow task',
            'status' => 'completed',
            'time_start' => '2025-02-01 00:00:00',
            'time_end' => '2025-02-01 00:30:00',
            'google_event_id' => null,
        ]);

        $this->client->request('GET', '/api/tasks/export?from=2025-01-01&to=2025-01-31');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('February overflow task', $body);
    }
}
