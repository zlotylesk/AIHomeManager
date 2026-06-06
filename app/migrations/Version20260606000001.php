<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HMAI-162: create videos table for YouTubeProgress module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE videos (
                youtube_id VARCHAR(20) NOT NULL,
                title VARCHAR(512) NOT NULL,
                channel VARCHAR(255) NOT NULL,
                duration_seconds INT NOT NULL,
                added_to_watchlist_at DATETIME NOT NULL,
                started_at DATETIME DEFAULT NULL,
                watched_at DATETIME DEFAULT NULL,
                PRIMARY KEY (youtube_id),
                INDEX idx_videos_channel (channel),
                INDEX idx_videos_started_at (started_at),
                INDEX idx_videos_watched_at (watched_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE videos');
    }
}
