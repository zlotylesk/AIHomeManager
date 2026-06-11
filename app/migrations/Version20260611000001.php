<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HMAI-179: add own (manual) rating_value column to series and series_seasons tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE series ADD rating_value INT DEFAULT NULL');
        $this->addSql('ALTER TABLE series_seasons ADD rating_value INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE series DROP rating_value');
        $this->addSql('ALTER TABLE series_seasons DROP rating_value');
    }
}
