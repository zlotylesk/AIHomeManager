<?php

declare(strict_types=1);

namespace App\Module\Tasks\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final class GoogleOAuthTokenRepository implements GoogleTokenRepositoryInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function get(): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT token_json FROM google_oauth_tokens ORDER BY id DESC LIMIT 1'
        );

        if ($row === false) {
            return null;
        }

        return json_decode($row['token_json'], true) ?: null;
    }

    public function save(array $token): void
    {
        $tokenJson = json_encode($token, JSON_THROW_ON_ERROR);
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM google_oauth_tokens');

        if ($count === 0) {
            $this->connection->executeStatement(
                'INSERT INTO google_oauth_tokens (token_json, created_at, updated_at) VALUES (:token, NOW(), NOW())',
                ['token' => $tokenJson]
            );
        } else {
            $this->connection->executeStatement(
                'UPDATE google_oauth_tokens SET token_json = :token, updated_at = NOW()',
                ['token' => $tokenJson]
            );
        }
    }
}
