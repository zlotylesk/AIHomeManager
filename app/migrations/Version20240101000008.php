<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix book_reading_sessions.notes column type to LONGTEXT to align with ORM mapping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book_reading_sessions CHANGE notes notes LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE book_reading_sessions CHANGE notes notes TEXT DEFAULT NULL');
    }
}
