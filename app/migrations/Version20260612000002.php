<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable unique trakt_id dedup key to series (HMAI-182)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE series ADD trakt_id VARCHAR(64) DEFAULT NULL');
        
        
        $this->addSql('CREATE UNIQUE INDEX uniq_series_trakt_id ON series (trakt_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_series_trakt_id ON series');
        $this->addSql('ALTER TABLE series DROP trakt_id');
    }
}
