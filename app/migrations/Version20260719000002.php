<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notifications table backing delivery state and dedup-key idempotency (HMAI-278)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notifications (
            id VARCHAR(36) NOT NULL,
            type VARCHAR(50) NOT NULL,
            channel VARCHAR(20) NOT NULL,
            payload JSON NOT NULL,
            dedup_key VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            failure_reason LONGTEXT DEFAULT NULL,
            UNIQUE INDEX uniq_notifications_dedup_key (dedup_key),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notifications');
    }
}
