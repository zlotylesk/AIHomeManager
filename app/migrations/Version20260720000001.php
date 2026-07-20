<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260720000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create podcasts + podcast_episodes tables (HMAI-322)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE podcasts (
                id VARCHAR(36) NOT NULL,
                title VARCHAR(500) NOT NULL,
                publisher VARCHAR(255) DEFAULT NULL,
                cover_url VARCHAR(1000) DEFAULT NULL,
                description LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE podcast_episodes (
                id VARCHAR(36) NOT NULL,
                podcast_id VARCHAR(36) NOT NULL,
                title VARCHAR(500) NOT NULL,
                published_at DATETIME DEFAULT NULL,
                duration_ms INT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_podcast_episodes_podcast (podcast_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE podcast_episodes');
        $this->addSql('DROP TABLE podcasts');
    }
}
