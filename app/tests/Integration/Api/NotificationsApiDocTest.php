<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the OpenAPI contract for the Notifications module (HMAI-283 documented the
 * operations; this closes the epic HMAI-275 test pyramid by asserting them): every
 * `/api/v1/notifications*` operation is documented, `Notifications`-tagged,
 * references the right read schema, and documents the preference/subscription
 * request bodies plus the shared error responses.
 *
 * Reaching parity with the other modules' *ApiDocTest matters because the runtime
 * conformance gate only checks responses it can produce — a request body or an
 * operation that quietly stopped being documented would slip past it.
 */
final class NotificationsApiDocTest extends WebTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}> label → [path, method]
     */
    public static function notificationOperations(): array
    {
        return [
            'preferences' => ['/api/v1/notifications/preferences', 'get'],
            'toggle type' => ['/api/v1/notifications/preferences/{type}/enabled', 'patch'],
            'set channel' => ['/api/v1/notifications/preferences/{type}/channels/{channel}', 'patch'],
            'quiet hours' => ['/api/v1/notifications/preferences/{type}/quiet-hours', 'put'],
            'push key' => ['/api/v1/notifications/push/key', 'get'],
            'subscribe' => ['/api/v1/notifications/push/subscriptions', 'post'],
            'unsubscribe' => ['/api/v1/notifications/push/subscriptions', 'delete'],
            'history' => ['/api/v1/notifications/history', 'get'],
        ];
    }

    public function testEveryNotificationOperationIsDocumentedAndTagged(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        foreach (self::notificationOperations() as $label => [$path, $method]) {
            $operation = $this->nestedArray($spec, 'paths', $path, $method);
            self::assertContains(
                'Notifications',
                $operation['tags'] ?? [],
                sprintf('%s %s (%s) must be tagged "Notifications".', strtoupper($method), $path, $label),
            );
        }
    }

    public function testTheNotificationsTagIsDeclared(): void
    {
        $spec = $this->fetchSpec(static::createClient());
        $names = array_column($spec['tags'] ?? [], 'name');

        self::assertContains('Notifications', $names, 'The Notifications tag must be declared so the docs group the operations.');
    }

    public function testPreferencesReturnAnArrayOfPreferenceModels(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/notifications/preferences', 'get');

        $schema = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('array', $schema['type'] ?? null);
        self::assertSame('#/components/schemas/NotificationPreferenceDTO', $schema['items']['$ref'] ?? null);
    }

    public function testHistoryReturnsAnArrayOfNotificationModelsAndDocumentsItsLimit(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/notifications/history', 'get');

        $schema = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('array', $schema['type'] ?? null);
        self::assertSame('#/components/schemas/NotificationDTO', $schema['items']['$ref'] ?? null);

        $limit = null;
        foreach ($get['parameters'] ?? [] as $parameter) {
            if ('limit' === ($parameter['name'] ?? null)) {
                $limit = $parameter;
            }
        }

        self::assertNotNull($limit, 'GET /notifications/history must document the "limit" query parameter.');
        // The documented bounds must match GetNotificationHistory's own guard,
        // otherwise a client trusting the contract gets a surprise 422.
        self::assertSame(1, $limit['schema']['minimum'] ?? null);
        self::assertSame(100, $limit['schema']['maximum'] ?? null);
    }

    public function testTheToggleBodiesRequireABooleanFlag(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        foreach (['/api/v1/notifications/preferences/{type}/enabled', '/api/v1/notifications/preferences/{type}/channels/{channel}'] as $path) {
            $body = $this->nestedArray($spec, 'paths', $path, 'patch', 'requestBody', 'content', 'application/json', 'schema');

            self::assertContains('enabled', $body['required'] ?? [], sprintf('%s must require "enabled".', $path));
            self::assertSame('boolean', $body['properties']['enabled']['type'] ?? null);
        }
    }

    public function testTheChannelParameterIsConstrainedToTheKnownChannels(): void
    {
        $patch = $this->nestedArray(
            $this->fetchSpec(static::createClient()),
            'paths',
            '/api/v1/notifications/preferences/{type}/channels/{channel}',
            'patch',
        );

        $channel = null;
        foreach ($patch['parameters'] ?? [] as $parameter) {
            if ('channel' === ($parameter['name'] ?? null)) {
                $channel = $parameter;
            }
        }

        self::assertNotNull($channel);
        self::assertSame(['email', 'push'], $channel['schema']['enum'] ?? null);
    }

    /**
     * Both ends are nullable on purpose — that is how the window is cleared, and
     * a contract that marked them required would hide the clearing path.
     */
    public function testTheQuietHoursBodyAllowsBothEndsToBeNull(): void
    {
        $body = $this->nestedArray(
            $this->fetchSpec(static::createClient()),
            'paths',
            '/api/v1/notifications/preferences/{type}/quiet-hours',
            'put',
            'requestBody',
            'content',
            'application/json',
            'schema',
        );

        self::assertArrayNotHasKey('required', $body);
        // OpenAPI 3.1 expresses nullability as a type union rather than the 3.0
        // `nullable` flag, so the contract must offer "null" alongside "string".
        self::assertSame(['string', 'null'], $body['properties']['from']['type'] ?? null);
        self::assertSame(['string', 'null'], $body['properties']['to']['type'] ?? null);
    }

    public function testTheSubscriptionBodyRequiresTheEndpointAndBothKeys(): void
    {
        $body = $this->nestedArray(
            $this->fetchSpec(static::createClient()),
            'paths',
            '/api/v1/notifications/push/subscriptions',
            'post',
            'requestBody',
            'content',
            'application/json',
            'schema',
        );

        foreach (['endpoint', 'publicKey', 'authToken'] as $field) {
            self::assertContains($field, $body['required'] ?? [], sprintf('The subscription body must require "%s".', $field));
        }
    }

    public function testThePushKeyEndpointExposesOnlyThePublicHalf(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/notifications/push/key', 'get');

        $properties = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema', 'properties');
        self::assertArrayHasKey('publicKey', $properties);
        self::assertArrayNotHasKey('privateKey', $properties, 'The VAPID private key must never appear in the contract.');
    }

    public function testWriteOperationsReferenceTheSharedErrorResponses(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        $writes = [
            ['/api/v1/notifications/preferences/{type}/enabled', 'patch'],
            ['/api/v1/notifications/preferences/{type}/channels/{channel}', 'patch'],
            ['/api/v1/notifications/preferences/{type}/quiet-hours', 'put'],
            ['/api/v1/notifications/push/subscriptions', 'post'],
            ['/api/v1/notifications/push/subscriptions', 'delete'],
        ];

        foreach ($writes as [$path, $method]) {
            $responses = $this->nestedArray($spec, 'paths', $path, $method, 'responses');

            self::assertArrayHasKey('204', $responses, sprintf('%s %s must document its 204.', strtoupper($method), $path));
            self::assertSame(
                '#/components/responses/UnprocessableEntityError',
                $responses['422']['$ref'] ?? null,
                sprintf('%s %s must $ref the shared 422.', strtoupper($method), $path),
            );
            self::assertSame(
                '#/components/responses/UnauthorizedError',
                $responses['401']['$ref'] ?? null,
                sprintf('%s %s must $ref the shared 401.', strtoupper($method), $path),
            );
        }
    }

    public function testThePreferenceSchemaMirrorsTheNormalizer(): void
    {
        $spec = $this->fetchSpec(static::createClient());
        $schema = $this->nestedArray($spec, 'components', 'schemas', 'NotificationPreferenceDTO');

        self::assertSame(
            ['type', 'enabled', 'channels', 'quietFrom', 'quietTo'],
            array_keys($schema['properties'] ?? []),
            'The documented shape must match NotificationPreferenceDTONormalizer field for field.',
        );
    }

    /**
     * @return array<mixed>
     */
    private function fetchSpec(KernelBrowser $client): array
    {
        $client->request('GET', '/api/doc.json');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'The OpenAPI spec must be reachable without an API key.');

        $content = $response->getContent();
        self::assertIsString($content);
        $doc = json_decode($content, true);
        self::assertIsArray($doc);

        return $doc;
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<mixed>
     */
    private function nestedArray(array $tree, string ...$keys): array
    {
        $node = $tree;
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $node, sprintf('Missing "%s" in the OpenAPI document.', $key));
            self::assertIsArray($node[$key], sprintf('"%s" must be an object in the OpenAPI document.', $key));
            $node = $node[$key];
        }

        return $node;
    }
}
