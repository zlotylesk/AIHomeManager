<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional catalog metadata (cover_url, year, status, description) to series (HMAI-190)';
    }

    public function up(Schema $schema): void
    {
        // All nullable — existing series predate the metadata and stay valid
        // with NULLs. status mirrors the book_status VARCHAR(20) enum column.
        $this->addSql('ALTER TABLE series
            ADD cover_url VARCHAR(1000) DEFAULT NULL,
            ADD year INT DEFAULT NULL,
            ADD status VARCHAR(20) DEFAULT NULL,
            ADD description LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE series
            DROP cover_url,
            DROP year,
            DROP status,
            DROP description');
    }
}
