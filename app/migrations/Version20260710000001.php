<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create goals table for the Goals module (HMAI-251)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE goals (
                id VARCHAR(36) NOT NULL,
                type VARCHAR(50) NOT NULL,
                target_value INT NOT NULL,
                period VARCHAR(20) NOT NULL,
                INDEX idx_goals_type (type),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE goals');
    }
}
