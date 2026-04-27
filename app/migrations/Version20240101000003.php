<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create article_daily_picks table for article-of-the-day history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE article_daily_picks (
                id VARCHAR(36) NOT NULL,
                article_id VARCHAR(36) NOT NULL,
                picked_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_article_daily_picks_picked_at (picked_at),
                INDEX idx_article_daily_picks_article_id (article_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE article_daily_picks');
    }
}
