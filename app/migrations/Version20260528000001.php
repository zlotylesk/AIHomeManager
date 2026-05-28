<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * HMAI-144: local listening-session history for the Music module — an
 * authoritative play log that survives Last.fm going away. dedup_hash carries a
 * unique index so repeated Last.fm polls are idempotent; played_at is indexed
 * for the descending history/range queries.
 */
final class Version20260528000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HMAI-144: create music_listening_sessions table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE music_listening_sessions (
                id VARCHAR(36) NOT NULL,
                artist VARCHAR(255) NOT NULL,
                title VARCHAR(500) NOT NULL,
                played_at DATETIME NOT NULL,
                source VARCHAR(50) NOT NULL,
                play_count INT DEFAULT NULL,
                dedup_hash VARCHAR(64) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_listening_sessions_dedup (dedup_hash),
                INDEX idx_listening_sessions_played_at (played_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE music_listening_sessions');
    }
}
