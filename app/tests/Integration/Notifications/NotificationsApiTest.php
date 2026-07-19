<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notifications;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NotificationsApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->executeStatement('DELETE FROM notification_preferences');
        $this->connection->executeStatement('DELETE FROM notifications');
        $this->connection->executeStatement('DELETE FROM push_subscriptions');
    }

    /**
     * An unconfigured type must still appear, showing the default that actually
     * governs delivery — otherwise the panel would render an empty row for a
     * type that is in fact enabled.
     */
    public function testPreferencesListEveryTypeIncludingUnconfiguredOnes(): void
    {
        $this->request('GET', '/api/v1/notifications/preferences');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonResponse($this->client);

        self::assertCount(4, $payload);
        self::assertSame('task_due', $payload[0]['type']);
        self::assertTrue($payload[0]['enabled']);
        self::assertSame(['email', 'push'], $payload[0]['channels']);
        self::assertNull($payload[0]['quietFrom']);

        // The daily digest ships opt-in: it appears in the panel but defaults
        // off, so the user is not double-notified out of the box. It still
        // carries every channel, ready to deliver the moment it is enabled.
        $digest = $this->preferenceFor($payload, 'daily_digest');
        self::assertFalse($digest['enabled']);
        self::assertSame(['email', 'push'], $digest['channels']);
    }

    public function testTogglingATypeIsReflectedInThePanel(): void
    {
        $this->request('PATCH', '/api/v1/notifications/preferences/task_due/enabled', (string) json_encode(['enabled' => false]));
        self::assertResponseStatusCodeSame(204);

        $this->request('GET', '/api/v1/notifications/preferences');
        $payload = $this->jsonResponse($this->client);

        self::assertFalse($this->preferenceFor($payload, 'task_due')['enabled']);
    }

    public function testTurningOffOneChannelLeavesTheOtherEnabled(): void
    {
        $this->request('PATCH', '/api/v1/notifications/preferences/task_due/channels/push', (string) json_encode(['enabled' => false]));
        self::assertResponseStatusCodeSame(204);

        $this->request('GET', '/api/v1/notifications/preferences');
        $payload = $this->jsonResponse($this->client);

        self::assertSame(['email'], $this->preferenceFor($payload, 'task_due')['channels']);
    }

    public function testSettingAndClearingQuietHours(): void
    {
        $this->request('PUT', '/api/v1/notifications/preferences/task_due/quiet-hours', (string) json_encode(['from' => '22:00', 'to' => '07:00']));
        self::assertResponseStatusCodeSame(204);

        $this->request('GET', '/api/v1/notifications/preferences');
        $preference = $this->preferenceFor($this->jsonResponse($this->client), 'task_due');
        self::assertSame('22:00', $preference['quietFrom']);
        self::assertSame('07:00', $preference['quietTo']);

        $this->request('PUT', '/api/v1/notifications/preferences/task_due/quiet-hours', (string) json_encode(['from' => null, 'to' => null]));
        self::assertResponseStatusCodeSame(204);

        $this->request('GET', '/api/v1/notifications/preferences');
        self::assertNull($this->preferenceFor($this->jsonResponse($this->client), 'task_due')['quietFrom']);
    }

    /**
     * A silently dropped half-range would persist as "no quiet hours".
     */
    public function testAHalfStatedQuietRangeIsRejected(): void
    {
        $this->request('PUT', '/api/v1/notifications/preferences/task_due/quiet-hours', (string) json_encode(['from' => '22:00']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnUnknownTypeIsRejected(): void
    {
        $this->request('PATCH', '/api/v1/notifications/preferences/smoke_signal/enabled', (string) json_encode(['enabled' => true]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testANonBooleanFlagIsRejected(): void
    {
        $this->request('PATCH', '/api/v1/notifications/preferences/task_due/enabled', (string) json_encode(['enabled' => 'yes']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisteringAPushSubscriptionIsIdempotent(): void
    {
        $body = (string) json_encode([
            'endpoint' => 'https://push.example.com/abc',
            'publicKey' => 'p256dh-value',
            'authToken' => 'auth-value',
        ]);

        $this->request('POST', '/api/v1/notifications/push/subscriptions', $body);
        self::assertResponseStatusCodeSame(204);

        $this->request('POST', '/api/v1/notifications/push/subscriptions', $body);
        self::assertResponseStatusCodeSame(204);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM push_subscriptions'));
    }

    public function testRemovingASubscriptionSucceedsEvenWhenItIsAlreadyGone(): void
    {
        $this->request('DELETE', '/api/v1/notifications/push/subscriptions', (string) json_encode(['endpoint' => 'https://push.example.com/never']));

        self::assertResponseStatusCodeSame(204);
    }

    public function testAnIncompleteSubscriptionIsRejected(): void
    {
        $this->request('POST', '/api/v1/notifications/push/subscriptions', (string) json_encode(['endpoint' => 'https://push.example.com/abc']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheVapidPublicKeyIsServed(): void
    {
        $this->request('GET', '/api/v1/notifications/push/key');

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('publicKey', $this->jsonResponse($this->client));
    }

    public function testHistoryReturnsTheNewestFirst(): void
    {
        $this->givenNotification('n-1', 'task_due', 'email', 'sent', '2026-07-18 08:00:00');
        $this->givenNotification('n-2', 'article_daily', 'push', 'failed', '2026-07-19 08:00:00');

        $this->request('GET', '/api/v1/notifications/history');

        self::assertResponseIsSuccessful();
        $payload = $this->jsonResponse($this->client);

        self::assertCount(2, $payload);
        self::assertSame('n-2', $payload[0]['id']);
        self::assertSame('failed', $payload[0]['status']);
        self::assertSame('n-1', $payload[1]['id']);
        self::assertNotNull($payload[1]['sentAt']);
    }

    public function testHistoryRejectsAnOutOfRangeLimit(): void
    {
        $this->request('GET', '/api/v1/notifications/history?limit=0');
        self::assertResponseStatusCodeSame(422);

        $this->request('GET', '/api/v1/notifications/history?limit=101');
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheLegacyAliasServesTheSamePayload(): void
    {
        $this->request('GET', '/api/v1/notifications/preferences');
        $versioned = $this->jsonResponse($this->client);

        $this->request('GET', '/api/notifications/preferences');
        $alias = $this->jsonResponse($this->client);

        self::assertSame($versioned, $alias);
    }

    public function testTheEndpointRequiresAnApiKey(): void
    {
        // setUp authenticated the browser; drop the header to exercise the firewall.
        $this->client->setServerParameter('HTTP_X_API_KEY', '');
        $this->client->request('GET', '/api/v1/notifications/preferences');

        self::assertResponseStatusCodeSame(401);
    }

    private function request(string $method, string $uri, ?string $content = null): void
    {
        $this->client->request($method, $uri, [], [], [], $content);
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function preferenceFor(array $payload, string $type): array
    {
        foreach ($payload as $preference) {
            self::assertIsArray($preference);

            if (($preference['type'] ?? null) === $type) {
                return $preference;
            }
        }

        self::fail(sprintf('No preference returned for type "%s".', $type));
    }

    private function givenNotification(string $id, string $type, string $channel, string $status, string $createdAt): void
    {
        $this->connection->insert('notifications', [
            'id' => $id,
            'type' => $type,
            'channel' => $channel,
            'payload' => (string) json_encode(['title' => 'Test']),
            'dedup_key' => $id.':subject:2026-07-19:'.$channel,
            'created_at' => $createdAt,
            'status' => $status,
            'sent_at' => 'sent' === $status ? $createdAt : null,
            'failure_reason' => 'failed' === $status ? 'transport refused' : null,
        ]);
    }
}
