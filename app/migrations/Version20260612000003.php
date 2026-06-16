<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add watched flag + watched_at to series_episodes (HMAI-188)';
    }

    public function up(Schema $schema): void
    {
        
        
        
        $this->addSql('ALTER TABLE series_episodes ADD watched TINYINT(1) NOT NULL DEFAULT 0, ADD watched_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE series_episodes DROP watched, DROP watched_at');
    }
}
