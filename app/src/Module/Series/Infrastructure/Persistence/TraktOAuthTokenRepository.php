<?php

declare(strict_types=1);

namespace App\Module\Series\Infrastructure\Persistence;

use App\Shared\Security\TokenCipherInterface;
use Doctrine\DBAL\Connection;

/**
 * Persists the Trakt OAuth2 token encrypted at rest (libsodium secretbox via
 * the {@see TokenCipherInterface} port). Mirrors the Tasks GoogleOAuthTokenRepository:
 * the whole token payload is JSON-encoded then encrypted into a single column. A
 * separate key (TRAKT_TOKEN_KEY) isolates the blast radius from Discogs/Google.
 */
final readonly class TraktOAuthTokenRepository implements TraktTokenRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private TokenCipherInterface $cipher,
    ) {
    }

    public function get(): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT token_json FROM trakt_oauth_tokens ORDER BY id DESC LIMIT 1'
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
        $tokenJson = json_encode($token, JSON_THROW_ON_ERROR);
        $encrypted = $this->cipher->encrypt($tokenJson);
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM trakt_oauth_tokens');

        if (0 === $count) {
            $this->connection->executeStatement(
                'INSERT INTO trakt_oauth_tokens (token_json, created_at, updated_at) VALUES (:token, NOW(), NOW())',
                ['token' => $encrypted]
            );
        } else {
            $this->connection->executeStatement(
                'UPDATE trakt_oauth_tokens SET token_json = :token, updated_at = NOW()',
                ['token' => $encrypted]
            );
        }
    }
}
