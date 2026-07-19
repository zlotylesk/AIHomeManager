<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notification_preferences table for the per-type/per-channel opt-in and quiet hours (HMAI-277)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_preferences (
            id VARCHAR(36) NOT NULL,
            type VARCHAR(50) NOT NULL,
            enabled TINYINT(1) DEFAULT 1 NOT NULL,
            channels JSON NOT NULL,
            quiet_hours VARCHAR(11) DEFAULT NULL,
            UNIQUE INDEX uniq_notification_preferences_type (type),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification_preferences');
    }
}
