<?php

declare(strict_types=1);

namespace App\Module\Tasks\Infrastructure\Google;

use App\Module\Tasks\Infrastructure\Persistence\GoogleTokenRepositoryInterface;
use App\Shared\Security\GoogleAccessTokenProviderInterface;
use Google\Client;
use Psr\Log\LoggerInterface;

/**
 * Hands out a usable Google OAuth access token, transparently refreshing it
 * via the OAuth2 refresh_token grant when the stored one has expired (the
 * TraktTokenProvider precedent — logic moved 1:1 out of
 * GoogleCalendarService::prepareAuthenticatedClient(), which now delegates
 * here). Both GoogleCalendarService (Tasks) and YouTubeApiClient
 * (YouTubeProgress, via the Shared port) go through this instead of reading
 * the raw stored token, so freshness stops depending on which module
 * happened to touch the shared credential most recently (HMAI-399).
 *
 * The injected Google\Client is the SHARED `google.client` container
 * service, also injected into GoogleCalendarService: setAccessToken() mutates
 * the client's in-memory token as a side effect, and GoogleCalendarService
 * relies on that very same instance already carrying a valid token by the
 * time it builds `new Calendar($this->client)` right after this call — the
 * two services MUST be wired to the same client instance.
 */
final readonly class GoogleAccessTokenProvider implements GoogleAccessTokenProviderInterface
{
    public function __construct(
        private Client $client,
        private GoogleTokenRepositoryInterface $tokenRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function getValidAccessToken(): ?string
    {
        $tokenData = $this->tokenRepository->get();

        if (null === $tokenData) {
            $this->logger->warning('Google OAuth: no token configured, cannot obtain access token');

            return null;
        }

        $this->client->setAccessToken($tokenData);

        if ($this->client->isAccessTokenExpired()) {
            $refreshToken = $this->client->getRefreshToken();
            if (null === $refreshToken) {
                $this->logger->warning('Google OAuth: refresh token missing, re-authentication required');

                return null;
            }

            $newToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
            if (isset($newToken['error'])) {
                $this->logger->warning('Google OAuth: token refresh failed, re-authentication required', [
                    'error' => $newToken['error'],
                    'error_description' => $newToken['error_description'] ?? '',
                ]);

                return null;
            }

            $this->tokenRepository->save($newToken);
            $this->client->setAccessToken($newToken);
        }

        $accessToken = $this->client->getAccessToken()['access_token'] ?? null;

        return is_string($accessToken) && '' !== $accessToken ? $accessToken : null;
    }
}
