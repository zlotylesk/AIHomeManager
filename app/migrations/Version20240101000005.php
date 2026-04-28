<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tasks table for Tasks module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE tasks (
                id VARCHAR(36) NOT NULL,
                title VARCHAR(255) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT 'pending',
                time_start DATETIME NOT NULL,
                time_end DATETIME NOT NULL,
                google_event_id VARCHAR(255) NULL,
                PRIMARY KEY (id),
                INDEX idx_tasks_status (status),
                INDEX idx_tasks_time_start (time_start)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tasks');
    }
}