<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public const HEADER = 'X-API-Key';

    public function __construct(private readonly string $expectedApiKey)
    {
    }

    public function supports(Request $request): ?bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        if ('' === $this->expectedApiKey) {
            throw new CustomUserMessageAuthenticationException('API key is not configured on the server.');
        }

        $providedKey = $request->headers->get(self::HEADER);
        if (null === $providedKey || '' === $providedKey) {
            throw new CustomUserMessageAuthenticationException('Missing API key.');
        }

        if (!hash_equals($this->expectedApiKey, $providedKey)) {
            throw new CustomUserMessageAuthenticationException('Invalid API key.');
        }

        return new SelfValidatingPassport(new UserBadge('api'));
    }

    public function onAuthenticationSuccess(Request $request, mixed $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['error' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
