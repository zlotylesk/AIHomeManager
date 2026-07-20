<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260721000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create spotify_oauth_tokens table for Spotify OAuth2 tokens (HMAI-323)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE spotify_oauth_tokens (
                    id INT NOT NULL AUTO_INCREMENT,
                    token_json TEXT NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE spotify_oauth_tokens');
    }
}
