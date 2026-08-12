<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ApiKeyAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class ApiKeyAuthenticatorTest extends TestCase
{
    public function testCurrentKeyIsAccepted(): void
    {
        $authenticator = new ApiKeyAuthenticator('current-key', 'previous-key');

        $passport = $authenticator->authenticate($this->requestWithKey('current-key'));

        self::assertInstanceOf(SelfValidatingPassport::class, $passport);
    }

    public function testPreviousKeyIsAcceptedDuringRotation(): void
    {
        $authenticator = new ApiKeyAuthenticator('current-key', 'previous-key');

        $passport = $authenticator->authenticate($this->requestWithKey('previous-key'));

        self::assertInstanceOf(SelfValidatingPassport::class, $passport);
    }

    public function testUnknownKeyIsRejected(): void
    {
        $authenticator = new ApiKeyAuthenticator('current-key', 'previous-key');

        $this->expectException(CustomUserMessageAuthenticationException::class);

        $authenticator->authenticate($this->requestWithKey('some-other-key'));
    }

    /**
     * With no rotation in progress (the default, unset `$previousApiKey`), a value
     * equal to the internal empty-string default must still be rejected — an empty
     * `API_KEY_PREVIOUS` means "no rotation", not "empty key accepted", so the
     * comparison must not degenerate into `hash_equals('', '')`.
     */
    public function testNoRotationConfiguredRejectsAnythingButTheCurrentKey(): void
    {
        $authenticator = new ApiKeyAuthenticator('current-key');

        $this->expectException(CustomUserMessageAuthenticationException::class);

        $authenticator->authenticate($this->requestWithKey('not-the-current-key'));
    }

    private function requestWithKey(string $key): Request
    {
        $request = Request::create('/api/series');
        $request->headers->set(ApiKeyAuthenticator::HEADER, $key);

        return $request;
    }
}
