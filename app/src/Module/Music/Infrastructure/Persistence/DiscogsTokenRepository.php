<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final class DiscogsTokenRepository implements DiscogsTokenRepositoryInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function get(): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT oauth_token, oauth_token_secret FROM discogs_oauth_tokens LIMIT 1');

        return $row ?: null;
    }

    public function save(string $oauthToken, string $oauthTokenSecret): void
    {
        $this->connection->executeStatement('DELETE FROM discogs_oauth_tokens');

        $this->connection->insert('discogs_oauth_tokens', [
            'oauth_token' => $oauthToken,
            'oauth_token_secret' => $oauthTokenSecret,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
