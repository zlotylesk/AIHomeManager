<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Security\ApiKeyAuthenticator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait AuthenticatedApiTrait
{
    private const TEST_API_KEY = 'test-api-key';

    private function authenticate(KernelBrowser $client): void
    {
        $client->setServerParameter('HTTP_'.str_replace('-', '_', strtoupper(ApiKeyAuthenticator::HEADER)), self::TEST_API_KEY);
    }

    /**
     * Decodes the client's last JSON response into a typed array, guaranteeing a
     * string body so PHPStan does not see the `string|false` of getContent().
     *
     * @return array<mixed>
     */
    private function jsonResponse(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The `data` array of a list response.
     *
     * Every list endpoint answers with the `{data, pagination}` envelope, so the
     * unwrapping lives here instead of being spelled out at each of the ~90
     * assertion sites — and a test that reaches for the items cannot silently
     * pass against a response that lost its envelope.
     *
     * @return array<mixed>
     */
    private function jsonList(KernelBrowser $client): array
    {
        $body = $this->jsonResponse($client);
        self::assertArrayHasKey('data', $body, 'A list response must carry the {data, pagination} envelope.');
        self::assertIsArray($body['data']);

        return $body['data'];
    }

    /**
     * The `pagination` block of a list response.
     *
     * @return array<string, mixed>
     */
    private function jsonPagination(KernelBrowser $client): array
    {
        $body = $this->jsonResponse($client);
        self::assertArrayHasKey('pagination', $body, 'A list response must carry the {data, pagination} envelope.');
        self::assertIsArray($body['pagination']);

        /** @var array<string, mixed> $pagination */
        $pagination = $body['pagination'];

        return $pagination;
    }
}
