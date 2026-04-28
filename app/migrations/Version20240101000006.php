<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove DEFAULT from tasks.status to align with ORM mapping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tasks CHANGE status status VARCHAR(50) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tasks CHANGE status status VARCHAR(50) NOT NULL DEFAULT 'pending'");
    }
}
