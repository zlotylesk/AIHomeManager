<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Infrastructure\Persistence;

use App\Shared\Security\TokenCipherInterface;
use Doctrine\DBAL\Connection;

/**
 * Persists the Spotify OAuth2 token encrypted at rest (libsodium secretbox via
 * the {@see TokenCipherInterface} port), mirroring the Trakt/Google token
 * repositories: the whole payload is JSON-encoded then encrypted into a single
 * column, and a separate key (SPOTIFY_TOKEN_KEY) isolates the blast radius.
 *
 * `created_at` is stamped here rather than in the callers, so every write —
 * initial exchange and refresh alike — leaves a token whose expiry can actually
 * be computed. Spotify omits an issue time from its token response.
 */
final readonly class SpotifyOAuthTokenRepository implements SpotifyTokenRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private TokenCipherInterface $cipher,
    ) {
    }

    public function get(): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT token_json FROM spotify_oauth_tokens ORDER BY id DESC LIMIT 1'
        );

        if (false === $row) {
            return null;
        }

        $plaintext = $this->cipher->decrypt((string) $row['token_json']);
        $decoded = json_decode($plaintext, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function save(array $token): void
    {
        $token['created_at'] ??= time();

        $tokenJson = json_encode($token, JSON_THROW_ON_ERROR);
        $encrypted = $this->cipher->encrypt($tokenJson);
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM spotify_oauth_tokens');

        if (0 === $count) {
            $this->connection->executeStatement(
                'INSERT INTO spotify_oauth_tokens (token_json, created_at, updated_at) VALUES (:token, NOW(), NOW())',
                ['token' => $encrypted]
            );
        } else {
            $this->connection->executeStatement(
                'UPDATE spotify_oauth_tokens SET token_json = :token, updated_at = NOW()',
                ['token' => $encrypted]
            );
        }
    }
}
