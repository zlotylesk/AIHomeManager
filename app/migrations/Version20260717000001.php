<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260717000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add trakt_id column + unique index to movies for the Trakt import dedup (HMAI-290)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE movies ADD trakt_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_movies_trakt_id ON movies (trakt_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_movies_trakt_id ON movies');
        $this->addSql('ALTER TABLE movies DROP trakt_id');
    }
}
