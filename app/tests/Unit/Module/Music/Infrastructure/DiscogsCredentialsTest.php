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
        // Sanity-check the happy path before exercising the debug-safety
        // contracts — if the basic accessors regress, every consumer breaks.
        $credentials = new DiscogsCredentials('public-key', 'plaintext-secret-DO-NOT-LEAK');

        self::assertSame('public-key', $credentials->consumerKey);
        self::assertSame('plaintext-secret-DO-NOT-LEAK', $credentials->consumerSecret);
    }

    public function testDebugInfoRedactsConsumerSecretButKeepsKey(): void
    {
        // Core HMAI-113 guarantee: __debugInfo() is what Symfony's VarDumper
        // (used by `debug:container --show-arguments`) consults when rendering
        // an object. The key is non-sensitive (public client identifier), the
        // secret must never appear in the rendered form.
        $credentials = new DiscogsCredentials('public-key', 'plaintext-secret-DO-NOT-LEAK');

        $debug = $credentials->__debugInfo();

        self::assertSame('public-key', $debug['consumerKey']);
        self::assertSame('***REDACTED***', $debug['consumerSecret']);
    }

    public function testVarDumpOutputDoesNotContainPlaintextSecret(): void
    {
        // End-to-end verification of the redaction contract. We capture the
        // exact bytes var_dump would print so a future change that drops
        // __debugInfo() (or adds the secret to its return value) fails here.
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
