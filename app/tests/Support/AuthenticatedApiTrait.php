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
}
