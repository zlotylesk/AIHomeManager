<?php

declare(strict_types=1);

namespace App\Tests\Integration\Goals;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GoalsApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private const string UNKNOWN_UUID = '00000000-0000-0000-0000-000000000000';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['goals', 'book_reading_sessions', 'series_episodes', 'articles', 'videos'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createGoal(array $overrides = []): string
    {
        $payload = array_merge(['type' => 'book_pages', 'target' => 50, 'period' => 'daily'], $overrides);
        $this->client->request('POST', '/api/goals', content: (string) json_encode($payload));
        self::assertResponseStatusCodeSame(201);

        $body = $this->jsonResponse($this->client);
        self::assertArrayHasKey('id', $body);
        self::assertIsString($body['id']);

        return $body['id'];
    }

    public function testListReturnsEmptyArrayWhenNoGoals(): void
    {
        $this->client->request('GET', '/api/goals');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonResponse($this->client));
    }

    public function testCreatedGoalAppearsInListWithZeroProgress(): void
    {
        $id = $this->createGoal();

        $this->client->request('GET', '/api/goals');
        self::assertResponseIsSuccessful();

        $list = $this->jsonResponse($this->client);
        self::assertCount(1, $list);
        self::assertSame($id, $list[0]['goalId']);
        self::assertSame('book_pages', $list[0]['type']);
        self::assertSame('daily', $list[0]['period']);
        self::assertSame(50, $list[0]['target']);
        self::assertSame(0, $list[0]['achieved']);
        self::assertSame(0, $list[0]['percent']);
        self::assertFalse($list[0]['met']);
    }

    public function testStreaksReturnsEntryPerGoalType(): void
    {
        $this->createGoal();

        $this->client->request('GET', '/api/goals/streaks');
        self::assertResponseIsSuccessful();

        $streaks = $this->jsonResponse($this->client);
        self::assertCount(1, $streaks);
        self::assertSame('book_pages', $streaks[0]['type']);
        self::assertSame(0, $streaks[0]['currentLength']);
        self::assertSame(0, $streaks[0]['longestLength']);
        self::assertNull($streaks[0]['lastActivityDate']);
    }

    public function testUpdateGoalChangesTargetAndPeriod(): void
    {
        $id = $this->createGoal();

        $this->client->request('PUT', '/api/goals/'.$id, content: (string) json_encode(['target' => 100, 'period' => 'weekly']));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/goals');
        $list = $this->jsonResponse($this->client);
        self::assertSame(100, $list[0]['target']);
        self::assertSame('weekly', $list[0]['period']);
    }

    public function testUpdateUnknownGoalReturns404(): void
    {
        $this->client->request('PUT', '/api/goals/'.self::UNKNOWN_UUID, content: (string) json_encode(['target' => 10, 'period' => 'daily']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteGoalRemovesIt(): void
    {
        $id = $this->createGoal();

        $this->client->request('DELETE', '/api/goals/'.$id);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/goals');
        self::assertSame([], $this->jsonResponse($this->client));
    }

    public function testDeleteUnknownGoalReturns404(): void
    {
        $this->client->request('DELETE', '/api/goals/'.self::UNKNOWN_UUID);

        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateWithUnknownTypeReturns422(): void
    {
        $this->client->request('POST', '/api/goals', content: (string) json_encode(['type' => 'bogus', 'target' => 50, 'period' => 'daily']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateWithMissingTargetReturns422(): void
    {
        $this->client->request('POST', '/api/goals', content: (string) json_encode(['type' => 'book_pages', 'period' => 'daily']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateWithNonPositiveTargetReturns422(): void
    {
        $this->client->request('POST', '/api/goals', content: (string) json_encode(['type' => 'book_pages', 'target' => 0, 'period' => 'daily']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testGoalsEndpointRejectsInvalidApiKey(): void
    {
        $this->client->setServerParameter('HTTP_X_API_KEY', 'wrong-key');
        $this->client->request('GET', '/api/goals');

        self::assertResponseStatusCodeSame(401);
    }
}
