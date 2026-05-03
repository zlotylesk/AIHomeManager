<?php

declare(strict_types=1);

namespace App\Module\Tasks\Infrastructure\Persistence;

use App\Security\TokenCipher;
use Doctrine\DBAL\Connection;

final readonly class GoogleOAuthTokenRepository implements GoogleTokenRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private TokenCipher $cipher,
    ) {
    }

    public function get(): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT token_json FROM google_oauth_tokens ORDER BY id DESC LIMIT 1'
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
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM google_oauth_tokens');

        if (0 === $count) {
            $this->connection->executeStatement(
                'INSERT INTO google_oauth_tokens (token_json, created_at, updated_at) VALUES (:token, NOW(), NOW())',
                ['token' => $encrypted]
            );
        } else {
            $this->connection->executeStatement(
                'UPDATE google_oauth_tokens SET token_json = :token, updated_at = NOW()',
                ['token' => $encrypted]
            );
        }
    }
}
