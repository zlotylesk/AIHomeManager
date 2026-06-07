<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260607000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HMAI-167: create watch_sessions and watch_session_videos tables for YouTubeProgress module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE watch_sessions (
                    id VARCHAR(36) NOT NULL,
                    total_duration_seconds INT NOT NULL,
                    created_at DATETIME NOT NULL,
                    youtube_playlist_id VARCHAR(64) DEFAULT NULL,
                    PRIMARY KEY (id),
                    INDEX idx_watch_sessions_created_at (created_at)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
                CREATE TABLE watch_session_videos (
                    watch_session_id VARCHAR(36) NOT NULL,
                    position INT NOT NULL,
                    youtube_video_id VARCHAR(20) NOT NULL,
                    PRIMARY KEY (watch_session_id, position),
                    INDEX idx_watch_session_videos_video (youtube_video_id),
                    CONSTRAINT fk_watch_session_videos_session FOREIGN KEY (watch_session_id) REFERENCES watch_sessions (id) ON DELETE CASCADE
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE watch_session_videos');
        $this->addSql('DROP TABLE watch_sessions');
    }
}
