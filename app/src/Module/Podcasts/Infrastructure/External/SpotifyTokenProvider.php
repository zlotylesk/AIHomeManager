<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Infrastructure\External;

use App\Module\Podcasts\Infrastructure\Persistence\SpotifyTokenRepositoryInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Hands out a usable Spotify access token, transparently refreshing it via the
 * OAuth2 refresh_token grant when the stored one has expired. The API client
 * injects this instead of touching the repository, so token freshness lives in
 * one place — the {@see \App\Module\Series\Infrastructure\External\TraktTokenProvider}
 * precedent.
 *
 * Two things differ from Trakt and drive the shape of this class:
 *
 * 1. Spotify's token response carries no issue time, so expiry is computed
 *    against the `created_at` the repository stamps on every write.
 * 2. A refresh response frequently omits `refresh_token` — Spotify only rotates
 *    it occasionally. Overwriting the stored payload wholesale would therefore
 *    discard the only credential able to refresh again, silently bricking the
 *    integration until the user re-authorizes. The refreshed payload is merged
 *    over the old one so anything the response omits survives.
 */
final readonly class SpotifyTokenProvider
{
    private const string TOKEN_URL = 'https://accounts.spotify.com/api/token';

    /**
     * Refresh this many seconds before real expiry, so a request issued right
     * after the check does not race the boundary.
     */
    private const int EXPIRY_SAFETY_MARGIN = 60;

    public function __construct(
        private SpotifyTokenRepositoryInterface $tokenRepository,
        private HttpClientInterface $httpClient,
        private string $clientId,
        private string $clientSecret,
    ) {
    }

    /**
     * @return string|null a valid access token, or null when Spotify has never
     *                     been connected or the refresh could not be completed
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

        // Merge, never replace: a response without `refresh_token` must not cost
        // us the one we already hold.
        $merged = array_merge($token, $refreshed);
        $merged['created_at'] = time();

        $this->tokenRepository->save($merged);

        return (string) $merged['access_token'];
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
        try {
            $response = $this->httpClient->request('POST', self::TOKEN_URL, [
                'headers' => [
                    'Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                return null;
            }

            $decoded = json_decode($response->getContent(false), true);
        } catch (TransportExceptionInterface) {
            return null;
        }

        return is_array($decoded) && isset($decoded['access_token']) ? $decoded : null;
    }
}
