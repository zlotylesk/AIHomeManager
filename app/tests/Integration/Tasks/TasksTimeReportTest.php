<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tasks;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TasksTimeReportTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement('TRUNCATE TABLE tasks');
    }

    public function testTimeReportSumsCompletedTasks(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        for ($i = 1; $i <= 3; $i++) {
            $start = new \DateTimeImmutable("2025-01-15 0{$i}:00:00");
            $end = $start->modify('+30 minutes');
            $conn->insert('tasks', [
                'id' => sprintf('task-%d000-0000-0000-000000000000', $i),
                'title' => 'Task ' . $i,
                'status' => 'completed',
                'time_start' => $start->format('Y-m-d H:i:s'),
                'time_end' => $end->format('Y-m-d H:i:s'),
                'google_event_id' => null,
            ]);
        }

        $this->client->request('GET', '/api/tasks/time-report?from=2025-01-01&to=2025-01-31');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(90, $data['totalMinutes']);
        self::assertSame(1.5, $data['totalHours']);
        self::assertCount(3, $data['breakdown']);
    }

    public function testTimeReportExcludesPendingAndCancelledTasks(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $conn->insert('tasks', [
            'id' => 'a0000001-0000-0000-0000-000000000000',
            'title' => 'Completed Task',
            'status' => 'completed',
            'time_start' => '2025-01-10 08:00:00',
            'time_end' => '2025-01-10 09:00:00',
            'google_event_id' => null,
        ]);
        $conn->insert('tasks', [
            'id' => 'a0000002-0000-0000-0000-000000000000',
            'title' => 'Pending Task',
            'status' => 'pending',
            'time_start' => '2025-01-10 10:00:00',
            'time_end' => '2025-01-10 11:00:00',
            'google_event_id' => null,
        ]);
        $conn->insert('tasks', [
            'id' => 'a0000003-0000-0000-0000-000000000000',
            'title' => 'Cancelled Task',
            'status' => 'cancelled',
            'time_start' => '2025-01-10 12:00:00',
            'time_end' => '2025-01-10 13:00:00',
            'google_event_id' => null,
        ]);

        $this->client->request('GET', '/api/tasks/time-report?from=2025-01-01&to=2025-01-31');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(60, $data['totalMinutes']);
        self::assertEquals(1.0, $data['totalHours']);
        self::assertCount(1, $data['breakdown']);
        self::assertSame('Completed Task', $data['breakdown'][0]['title']);
    }

    public function testTimeReportReturnsEmptyWhenNoTasksInRange(): void
    {
        $this->client->request('GET', '/api/tasks/time-report?from=2025-01-01&to=2025-01-31');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(0, $data['totalMinutes']);
        self::assertEquals(0.0, $data['totalHours']);
        self::assertSame([], $data['breakdown']);
    }

    public function testTimeReportReturns422WhenMissingFromParam(): void
    {
        $this->client->request('GET', '/api/tasks/time-report?to=2025-01-31');

        self::assertResponseStatusCodeSame(422);
    }

    public function testTimeReportReturns422WhenMissingToParam(): void
    {
        $this->client->request('GET', '/api/tasks/time-report?from=2025-01-01');

        self::assertResponseStatusCodeSame(422);
    }

    public function testTimeReportReturns422WhenInvalidDateFormat(): void
    {
        $this->client->request('GET', '/api/tasks/time-report?from=not-a-date&to=2025-01-31');

        self::assertResponseStatusCodeSame(422);
    }
}
