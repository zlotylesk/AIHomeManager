<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tasks;

use App\Tests\Support\AuthenticatedApiTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TasksTimeReportTest extends WebTestCase
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

    public function testTimeReportSumsCompletedTasks(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        for ($i = 1; $i <= 3; ++$i) {
            $start = new DateTimeImmutable("2025-01-15 0{$i}:00:00");
            $end = $start->modify('+30 minutes');
            $conn->insert('tasks', [
                'id' => sprintf('task-%d000-0000-0000-000000000000', $i),
                'title' => 'Task '.$i,
                'status' => 'completed',
                'time_start' => $start->format('Y-m-d H:i:s'),
                'time_end' => $end->format('Y-m-d H:i:s'),
                'google_event_id' => null,
            ]);
        }

        $this->client->request('GET', '/api/tasks/time-report?from=2025-01-01&to=2025-01-31');

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
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
        $data = $this->jsonResponse($this->client);
        self::assertSame(60, $data['totalMinutes']);
        self::assertEquals(1.0, $data['totalHours']);
        self::assertCount(1, $data['breakdown']);
        self::assertSame('Completed Task', $data['breakdown'][0]['title']);
    }

    public function testTimeReportReturnsEmptyWhenNoTasksInRange(): void
    {
        $this->client->request('GET', '/api/tasks/time-report?from=2025-01-01&to=2025-01-31');

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
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

    /**
     * HMAI-400: `to` is documented as an inclusive upper bound on the task
     * start. A task starting mid-day on the `to` date itself used to be
     * silently excluded because a bare date parses to midnight; this pins
     * that the whole day named by `to` is now covered.
     */
    public function testTimeReportIncludesTaskStartingOnLastDayOfRange(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->insert('tasks', [
            'id' => 'b0000001-0000-0000-0000-000000000000',
            'title' => 'Last day task',
            'status' => 'completed',
            'time_start' => '2025-01-31 23:30:00',
            'time_end' => '2025-01-31 23:59:00',
            'google_event_id' => null,
        ]);

        $this->client->request('GET', '/api/tasks/time-report?from=2025-01-31&to=2025-01-31');

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
        self::assertSame(29, $data['totalMinutes']);
        self::assertCount(1, $data['breakdown']);
        self::assertSame('Last day task', $data['breakdown'][0]['title']);
    }

    /**
     * HMAI-400: the inclusive-day widening must stop exactly at the next
     * day's midnight — a task starting at `D+1 00:00:00` is one range-width
     * over and must stay excluded, otherwise the fix would have just moved
     * the off-by-one bug forward by a day.
     */
    public function testTimeReportExcludesTaskStartingAfterUpperBoundDay(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->insert('tasks', [
            'id' => 'b0000002-0000-0000-0000-000000000000',
            'title' => 'Next day task',
            'status' => 'completed',
            'time_start' => '2025-02-01 00:00:00',
            'time_end' => '2025-02-01 00:30:00',
            'google_event_id' => null,
        ]);

        $this->client->request('GET', '/api/tasks/time-report?from=2025-01-01&to=2025-01-31');

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
        self::assertSame(0, $data['totalMinutes']);
        self::assertSame([], $data['breakdown']);
    }

    /**
     * HMAI-400: only a bare YYYY-MM-DD `to` is widened to end-of-day. A `to`
     * that already carries a time component is a deliberate partial-day
     * cutoff from the caller and must not be silently pushed to 23:59:59.
     */
    public function testTimeReportUpperBoundWithTimeComponentIsNotWidenedToEndOfDay(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->insert('tasks', [
            'id' => 'b0000003-0000-0000-0000-000000000000',
            'title' => 'Afternoon task',
            'status' => 'completed',
            'time_start' => '2025-01-15 16:00:00',
            'time_end' => '2025-01-15 16:30:00',
            'google_event_id' => null,
        ]);

        $this->client->request('GET', '/api/tasks/time-report?from=2025-01-15&to=2025-01-15T15:00:00');

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
        self::assertSame(0, $data['totalMinutes']);
        self::assertSame([], $data['breakdown']);
    }
}
