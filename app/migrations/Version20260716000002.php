<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260716000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add catalog metadata columns (cover_url/year/status/description) to movies (HMAI-289)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE movies
                ADD cover_url VARCHAR(1000) DEFAULT NULL,
                ADD year INT DEFAULT NULL,
                ADD status VARCHAR(20) DEFAULT NULL,
                ADD description LONGTEXT DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE movies DROP cover_url, DROP year, DROP status, DROP description');
    }
}
