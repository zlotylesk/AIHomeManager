<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * HMAI-61: add lookup/FK indexes for tables that grow over time.
 *
 * Without these, every JOIN/ORDER BY pays a full scan as data accumulates:
 *  - series_episodes.season_id is the FK used by the SeriesDetail JOIN,
 *  - series.created_at drives the default ORDER BY in GetAllSeriesHandler,
 *  - articles.added_at backs the GetArticleOfTheDay candidate scan.
 */
final class Version20260517000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HMAI-61: add indexes (series_episodes.season_id, series.created_at, articles.added_at)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_episode_season_id ON series_episodes (season_id)');
        $this->addSql('CREATE INDEX idx_series_created_at ON series (created_at)');
        $this->addSql('CREATE INDEX idx_article_added_at ON articles (added_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_episode_season_id ON series_episodes');
        $this->addSql('DROP INDEX idx_series_created_at ON series');
        $this->addSql('DROP INDEX idx_article_added_at ON articles');
    }
}
