<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create streaks table for the Goals background streak recalc (HMAI-255)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE streaks (
                id VARCHAR(36) NOT NULL,
                type VARCHAR(50) NOT NULL,
                current_length INT NOT NULL,
                longest_length INT NOT NULL,
                last_activity_date DATETIME DEFAULT NULL,
                UNIQUE INDEX uniq_streaks_type (type),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE streaks');
    }
}
