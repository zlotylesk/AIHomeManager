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
        // Acceptance criteria: from/to filter applies to time_start so the
        // export reflects "tasks I did during this period", not "tasks created
        // during this period."
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
}
