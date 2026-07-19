<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create push_subscriptions table backing the Web Push channel (HMAI-280)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE push_subscriptions (
            id VARCHAR(36) NOT NULL,
            endpoint VARCHAR(500) NOT NULL,
            p256dh VARCHAR(255) NOT NULL,
            auth_token VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_push_subscriptions_endpoint (endpoint),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE push_subscriptions');
    }
}
