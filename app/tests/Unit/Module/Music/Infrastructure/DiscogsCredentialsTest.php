<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Infrastructure;

use App\Module\Music\Infrastructure\External\DiscogsCredentials;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DiscogsCredentialsTest extends TestCase
{
    public function testExposesConsumerKeyAndSecretAsReadOnlyProperties(): void
    {
        $credentials = new DiscogsCredentials('public-key', 'plaintext-secret-DO-NOT-LEAK');

        self::assertSame('public-key', $credentials->consumerKey);
        self::assertSame('plaintext-secret-DO-NOT-LEAK', $credentials->consumerSecret);
    }

    public function testDebugInfoRedactsConsumerSecretButKeepsKey(): void
    {
        $credentials = new DiscogsCredentials('public-key', 'plaintext-secret-DO-NOT-LEAK');

        $debug = $credentials->__debugInfo();

        self::assertSame('public-key', $debug['consumerKey']);
        self::assertSame('***REDACTED***', $debug['consumerSecret']);
    }

    public function testVarDumpOutputDoesNotContainPlaintextSecret(): void
    {
        $credentials = new DiscogsCredentials('public-key', 'plaintext-secret-DO-NOT-LEAK');

        ob_start();
        var_dump($credentials);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('plaintext-secret-DO-NOT-LEAK', $dump);
        self::assertStringContainsString('***REDACTED***', $dump);
    }

    public function testRejectsEmptyConsumerKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Discogs consumer key cannot be empty.');

        new DiscogsCredentials('', 'secret');
    }

    public function testRejectsWhitespaceOnlyConsumerKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Discogs consumer key cannot be empty.');

        new DiscogsCredentials("  \t\n", 'secret');
    }

    public function testRejectsEmptyConsumerSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Discogs consumer secret cannot be empty.');

        new DiscogsCredentials('key', '');
    }

    public function testRejectsWhitespaceOnlyConsumerSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Discogs consumer secret cannot be empty.');

        new DiscogsCredentials('key', "  \t\n");
    }
}
