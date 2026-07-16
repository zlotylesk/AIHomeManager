<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add watched/watched_at/user_rating columns to movies (HMAI-288)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE movies
                ADD watched TINYINT(1) DEFAULT 0 NOT NULL,
                ADD watched_at DATETIME DEFAULT NULL,
                ADD user_rating INT DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE movies DROP watched, DROP watched_at, DROP user_rating');
    }
}
