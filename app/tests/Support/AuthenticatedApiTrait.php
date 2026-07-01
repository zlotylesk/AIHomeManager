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
}
