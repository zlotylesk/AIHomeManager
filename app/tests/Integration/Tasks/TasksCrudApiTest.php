<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tasks;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TasksCrudApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        static::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()->executeStatement('TRUNCATE TABLE tasks');
    }

    private function createTask(string $title = 'Buy groceries', string $start = '2025-06-01T09:00:00+02:00', string $end = '2025-06-01T10:00:00+02:00'): string
    {
        $this->client->request('POST', '/api/tasks', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'title' => $title,
            'start' => $start,
            'end' => $end,
        ]));

        self::assertResponseStatusCodeSame(201);

        return json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
    }

    public function testCreateTaskReturns201WithId(): void
    {
        $id = $this->createTask();

        self::assertNotEmpty($id);
        self::assertSame(36, strlen($id));
    }

    public function testCreateTaskMissingTitleReturns422(): void
    {
        $this->client->request('POST', '/api/tasks', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'start' => '2025-06-01T09:00:00+02:00',
            'end' => '2025-06-01T10:00:00+02:00',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateTaskEndBeforeStartReturns422(): void
    {
        $this->client->request('POST', '/api/tasks', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'title' => 'Bad task',
            'start' => '2025-06-01T10:00:00+02:00',
            'end' => '2025-06-01T09:00:00+02:00',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testListTasksReturnsArray(): void
    {
        $this->createTask('Task A');
        $this->createTask('Task B');

        $this->client->request('GET', '/api/tasks');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(2, $data);
    }

    public function testListTasksFilterByStatus(): void
    {
        $id = $this->createTask();

        $this->client->request('POST', '/api/tasks/'.$id.'/complete');
        self::assertResponseStatusCodeSame(204);

        $this->createTask('Pending task');

        $this->client->request('GET', '/api/tasks?status=completed');
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('completed', $data[0]['status']);
    }

    public function testListTasksInvalidStatusReturns422(): void
    {
        $this->client->request('GET', '/api/tasks?status=invalid');

        self::assertResponseStatusCodeSame(422);
    }

    public function testDetailTaskReturns200(): void
    {
        $id = $this->createTask('Detail task');

        $this->client->request('GET', '/api/tasks/'.$id);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Detail task', $data['title']);
        self::assertSame('pending', $data['status']);
    }

    public function testDetailTaskNotFoundReturns404(): void
    {
        $this->client->request('GET', '/api/tasks/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateTaskTitle(): void
    {
        $id = $this->createTask();

        $this->client->request('PATCH', '/api/tasks/'.$id, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'title' => 'Updated title',
        ]));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/tasks/'.$id);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Updated title', $data['title']);
    }

    public function testUpdateTaskNotFoundReturns404(): void
    {
        $this->client->request('PATCH', '/api/tasks/00000000-0000-0000-0000-000000000000', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'title' => 'Does not exist',
        ]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testCompleteTask(): void
    {
        $id = $this->createTask();

        $this->client->request('POST', '/api/tasks/'.$id.'/complete');

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/tasks/'.$id);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('completed', $data['status']);
    }

    public function testCompleteTaskNotFoundReturns404(): void
    {
        $this->client->request('POST', '/api/tasks/00000000-0000-0000-0000-000000000000/complete');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCancelTask(): void
    {
        $id = $this->createTask();

        $this->client->request('POST', '/api/tasks/'.$id.'/cancel');

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/tasks/'.$id);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('cancelled', $data['status']);
    }

    public function testCancelTaskNotFoundReturns404(): void
    {
        $this->client->request('POST', '/api/tasks/00000000-0000-0000-0000-000000000000/cancel');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteTask(): void
    {
        $id = $this->createTask();

        $this->client->request('DELETE', '/api/tasks/'.$id);

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/tasks/'.$id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteTaskNotFoundReturns404(): void
    {
        $this->client->request('DELETE', '/api/tasks/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCompleteAlreadyCompletedTaskReturns422(): void
    {
        $id = $this->createTask();

        $this->client->request('POST', '/api/tasks/'.$id.'/complete');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('POST', '/api/tasks/'.$id.'/complete');
        self::assertResponseStatusCodeSame(422);
    }

    public function testCancelAlreadyCancelledTaskReturns422(): void
    {
        $id = $this->createTask();

        $this->client->request('POST', '/api/tasks/'.$id.'/cancel');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('POST', '/api/tasks/'.$id.'/cancel');
        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateTaskWithoutApiKeyReturns401(): void
    {
        $this->client->setServerParameter('HTTP_X_API_KEY', 'wrong-key');
        $this->client->request('POST', '/api/tasks', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode([
            'title' => 'No auth',
            'start' => '2025-06-01T09:00:00+02:00',
            'end' => '2025-06-01T10:00:00+02:00',
        ]));

        self::assertResponseStatusCodeSame(401);
    }
}
