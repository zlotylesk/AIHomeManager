<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\Persistence;

use App\Module\Music\Infrastructure\Security\TokenCipher;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DiscogsTokenRepository implements DiscogsTokenRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private TokenCipher $cipher,
    ) {
    }

    public function get(): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT oauth_token, oauth_token_secret FROM discogs_oauth_tokens LIMIT 1');

        if (false === $row) {
            return null;
        }

        return [
            'oauth_token' => $this->cipher->decrypt($row['oauth_token']),
            'oauth_token_secret' => $this->cipher->decrypt($row['oauth_token_secret']),
        ];
    }

    public function save(string $oauthToken, string $oauthTokenSecret): void
    {
        $this->connection->executeStatement('DELETE FROM discogs_oauth_tokens');

        $this->connection->insert('discogs_oauth_tokens', [
            'oauth_token' => $this->cipher->encrypt($oauthToken),
            'oauth_token_secret' => $this->cipher->encrypt($oauthTokenSecret),
            'created_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
            'updated_at' => new DateTimeImmutable()->format('Y-m-d H:i:s'),
        ]);
    }
}
