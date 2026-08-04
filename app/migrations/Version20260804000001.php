<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the date columns the cross-module activity readers filter on (articles, series_episodes, book_reading_sessions)';
    }

    public function up(Schema $schema): void
    {
        // Goals, Insights, the cockpit and the weekly report all read these three
        // tables by a date range, and none of the three columns carried an index —
        // so every one of those reads was a full table scan whose cost grows with
        // the library. `music_listening_sessions.played_at`, `videos.watched_at`
        // and `tasks.time_start` already had one; these three had fallen out of a
        // rule the project was otherwise following.
        //
        // Two of the three predicates are compound, so the index leads with the
        // equality column and the range follows — the shape MySQL can actually use
        // for both parts. Measured on 20k rows spread over five years, each index
        // also turns out to be *covering* for every reader (`Using index`, no row
        // lookups at all), because between them the two columns are the whole of
        // what those queries select. That is why `book_reading_sessions` carries
        // `pages_read` as a second column even though nothing filters on it: it is
        // 4 bytes per row that removes ~4000 random row lookups per streak read.
        //
        //   articles              is_read = 1 AND read_at BETWEEN ...
        //   series_episodes       watched = 1 AND watched_at BETWEEN ...
        //   book_reading_sessions date BETWEEN ...                  (no equality half)
        $this->addSql('CREATE INDEX idx_article_is_read_read_at ON articles (is_read, read_at)');
        $this->addSql('CREATE INDEX idx_episode_watched_watched_at ON series_episodes (watched, watched_at)');
        $this->addSql('CREATE INDEX idx_reading_sessions_date_pages_read ON book_reading_sessions (date, pages_read)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_article_is_read_read_at ON articles');
        $this->addSql('DROP INDEX idx_episode_watched_watched_at ON series_episodes');
        $this->addSql('DROP INDEX idx_reading_sessions_date_pages_read ON book_reading_sessions');
    }
}
