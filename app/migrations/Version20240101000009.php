<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create discogs_oauth_tokens table for Discogs OAuth1 tokens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE discogs_oauth_tokens (
                id INT NOT NULL AUTO_INCREMENT,
                oauth_token VARCHAR(255) NOT NULL,
                oauth_token_secret VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE discogs_oauth_tokens');
    }
}
