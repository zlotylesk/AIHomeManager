<?php

declare(strict_types=1);

namespace App\Module\Series\Infrastructure\External;

use App\Module\Series\Infrastructure\Persistence\TraktTokenRepositoryInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Hands out a usable Trakt access token, transparently refreshing it via the
 * OAuth2 refresh_token grant when the stored one has expired. Layer 2 (the
 * TraktApiClient, HMAI-181) injects this instead of touching the repository
 * directly, so token freshness stays in one place.
 */
final readonly class TraktTokenProvider
{
    private const string TOKEN_URL = 'https://api.trakt.tv/oauth/token';

    /**
     * Refresh a token this many seconds before its real expiry, so a request
     * issued right after the check does not race the expiry boundary.
     */
    private const int EXPIRY_SAFETY_MARGIN = 60;

    public function __construct(
        private TraktTokenRepositoryInterface $tokenRepository,
        private HttpClientInterface $httpClient,
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
    ) {
    }

    /**
     * @return string|null a valid access token, or null when the user has never connected Trakt
     */
    public function getValidAccessToken(): ?string
    {
        $token = $this->tokenRepository->get();

        if (null === $token || !isset($token['access_token'])) {
            return null;
        }

        if (!$this->isExpired($token)) {
            return (string) $token['access_token'];
        }

        $refreshToken = $token['refresh_token'] ?? null;

        if (!is_string($refreshToken) || '' === $refreshToken) {
            return null;
        }

        $refreshed = $this->refresh($refreshToken);

        if (null === $refreshed) {
            return null;
        }

        $this->tokenRepository->save($refreshed);

        return isset($refreshed['access_token']) ? (string) $refreshed['access_token'] : null;
    }

    /**
     * @param array<string, mixed> $token
     */
    private function isExpired(array $token): bool
    {
        $createdAt = isset($token['created_at']) ? (int) $token['created_at'] : 0;
        $expiresIn = isset($token['expires_in']) ? (int) $token['expires_in'] : 0;

        if (0 === $createdAt || 0 === $expiresIn) {
            return true;
        }

        return time() >= ($createdAt + $expiresIn - self::EXPIRY_SAFETY_MARGIN);
    }

    /**
     * @return array<string, mixed>|null the new token payload, or null on failure
     */
    private function refresh(string $refreshToken): ?array
    {
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'json' => [
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectUri,
                'grant_type' => 'refresh_token',
            ],
        ]);

        if (200 !== $response->getStatusCode()) {
            return null;
        }

        $decoded = json_decode($response->getContent(false), true);

        return is_array($decoded) && isset($decoded['access_token']) ? $decoded : null;
    }
}
