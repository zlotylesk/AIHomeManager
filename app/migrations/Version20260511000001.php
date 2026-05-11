<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * HMAI-64: Add expires_at to discogs_oauth_tokens.
 *
 * Discogs OAuth1 tokens are indefinite — this column is always NULL in practice.
 * It is reserved for future proactive-expiry logic (e.g. prompt re-auth after N days).
 * Revocation is detected at call-time via DiscogsAuthException (HMAI-63).
 */
final class Version20260511000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HMAI-64: add expires_at (nullable) to discogs_oauth_tokens for future proactive re-auth support';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discogs_oauth_tokens ADD expires_at DATETIME NULL DEFAULT NULL AFTER oauth_token_secret');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discogs_oauth_tokens DROP expires_at');
    }
}
