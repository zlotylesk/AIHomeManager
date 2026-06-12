<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create trakt_oauth_tokens table for Trakt.tv OAuth2 tokens (HMAI-180)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE trakt_oauth_tokens (
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
        $this->addSql('DROP TABLE trakt_oauth_tokens');
    }
}
