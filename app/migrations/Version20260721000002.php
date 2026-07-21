<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260721000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create podcast_listening_sessions + external_id keys on the podcast catalog (HMAI-324)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE podcasts ADD external_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_podcasts_external ON podcasts (external_id)');

        $this->addSql('ALTER TABLE podcast_episodes ADD external_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_podcast_episodes_external ON podcast_episodes (external_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE podcast_listening_sessions (
                id VARCHAR(36) NOT NULL,
                podcast_id VARCHAR(36) NOT NULL,
                episode_id VARCHAR(36) NOT NULL,
                listened_at DATETIME NOT NULL,
                resume_position_ms INT NOT NULL,
                fully_played TINYINT(1) NOT NULL,
                dedup_hash VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_podcast_sessions_dedup (dedup_hash),
                INDEX idx_podcast_sessions_listened_at (listened_at),
                INDEX idx_podcast_sessions_episode (episode_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE podcast_listening_sessions');

        $this->addSql('DROP INDEX uniq_podcast_episodes_external ON podcast_episodes');
        $this->addSql('ALTER TABLE podcast_episodes DROP external_id');

        $this->addSql('DROP INDEX uniq_podcasts_external ON podcasts');
        $this->addSql('ALTER TABLE podcasts DROP external_id');
    }
}
