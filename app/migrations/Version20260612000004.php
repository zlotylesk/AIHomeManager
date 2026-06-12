<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add real episode number to series_episodes, backfilled per season (HMAI-187)';
    }

    public function up(Schema $schema): void
    {
        // Add nullable first, backfill each season's episodes with a sequential
        // number matching their current display order (the read query ordered by
        // e.id), then enforce NOT NULL. The per-season ROW_NUMBER keeps numbers
        // unique within a season — the invariant Season::addEpisode() guards.
        $this->addSql('ALTER TABLE series_episodes ADD number INT DEFAULT NULL');
        $this->addSql(
            'UPDATE series_episodes e
             JOIN (
                 SELECT id, ROW_NUMBER() OVER (PARTITION BY season_id ORDER BY id) AS rn
                 FROM series_episodes
             ) ordered ON ordered.id = e.id
             SET e.number = ordered.rn'
        );
        $this->addSql('ALTER TABLE series_episodes MODIFY number INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE series_episodes DROP number');
    }
}
