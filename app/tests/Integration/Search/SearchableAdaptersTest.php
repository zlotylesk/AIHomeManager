<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Infrastructure\Provider\ArticlesSearchableProvider;
use App\Module\Search\Infrastructure\Provider\BooksSearchableProvider;
use App\Module\Search\Infrastructure\Provider\MusicSearchableProvider;
use App\Module\Search\Infrastructure\Provider\SeriesSearchableProvider;
use App\Module\Search\Infrastructure\Provider\TasksSearchableProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SearchableAdaptersTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['books', 'articles', 'series', 'tasks', 'music_listening_sessions'] as $table) {
            $this->connection->executeStatement('TRUNCATE TABLE '.$table);
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testBooksAdapterExposesTitleAndAuthor(): void
    {
        $this->connection->insert('books', [
            'id' => 'b-1', 'isbn' => '9780000000001', 'title' => 'Dune', 'author' => 'Frank Herbert',
            'publisher' => 'Ace', 'year' => 1965, 'current_page' => 0, 'total_pages' => 412, 'status' => 'reading',
        ]);

        $documents = new BooksSearchableProvider($this->connection)->documents();

        self::assertCount(1, $documents);
        self::assertSame(SearchResultType::BOOK, $documents[0]->type);
        self::assertSame('b-1', $documents[0]->id);
        self::assertSame('Dune', $documents[0]->title);
        self::assertSame('Frank Herbert', $documents[0]->content);
        self::assertSame('/books', $documents[0]->url);
    }

    public function testArticlesAdapterExposesTitleAndCategory(): void
    {
        $this->connection->insert('articles', [
            'id' => 'a-1', 'title' => 'Hexagonal Architecture', 'url' => 'https://example.test/hex',
            'added_at' => '2026-07-01 00:00:00', 'is_read' => 0, 'category' => 'Architecture',
        ]);

        $documents = new ArticlesSearchableProvider($this->connection)->documents();

        self::assertCount(1, $documents);
        self::assertSame(SearchResultType::ARTICLE, $documents[0]->type);
        self::assertSame('Hexagonal Architecture', $documents[0]->title);
        self::assertSame('Architecture', $documents[0]->content);
        self::assertSame('/articles', $documents[0]->url);
    }

    public function testSeriesAdapterExposesTitleAndDescription(): void
    {
        $this->connection->insert('series', [
            'id' => 's-1', 'title' => 'Severance', 'created_at' => '2026-07-01 00:00:00',
            'description' => 'work-life balance',
        ]);

        $documents = new SeriesSearchableProvider($this->connection)->documents();

        self::assertCount(1, $documents);
        self::assertSame(SearchResultType::SERIES, $documents[0]->type);
        self::assertSame('Severance', $documents[0]->title);
        self::assertSame('work-life balance', $documents[0]->content);
        self::assertSame('/series', $documents[0]->url);
    }

    public function testTasksAdapterExposesTitleWithEmptyBody(): void
    {
        $this->connection->insert('tasks', [
            'id' => 't-1', 'title' => 'Buy milk', 'time_start' => '2026-07-01 08:00:00',
            'time_end' => '2026-07-01 08:30:00', 'status' => 'pending',
        ]);

        $documents = new TasksSearchableProvider($this->connection)->documents();

        self::assertCount(1, $documents);
        self::assertSame(SearchResultType::TASK, $documents[0]->type);
        self::assertSame('Buy milk', $documents[0]->title);
        self::assertSame('', $documents[0]->content);
        self::assertSame('/tasks', $documents[0]->url);
    }

    public function testMusicAdapterDeduplicatesAlbumsAcrossPlays(): void
    {
        $this->connection->insert('music_listening_sessions', [
            'id' => 'm-1', 'artist' => 'The Beatles', 'title' => 'Abbey Road', 'played_at' => '2026-07-01 10:00:00',
            'source' => 'lastfm', 'dedup_hash' => 'h1', 'created_at' => '2026-07-01 10:00:00',
        ]);
        $this->connection->insert('music_listening_sessions', [
            'id' => 'm-2', 'artist' => 'The Beatles', 'title' => 'Abbey Road', 'played_at' => '2026-07-02 10:00:00',
            'source' => 'lastfm', 'dedup_hash' => 'h2', 'created_at' => '2026-07-02 10:00:00',
        ]);
        $this->connection->insert('music_listening_sessions', [
            'id' => 'm-3', 'artist' => 'Pink Floyd', 'title' => 'The Wall', 'played_at' => '2026-07-03 10:00:00',
            'source' => 'lastfm', 'dedup_hash' => 'h3', 'created_at' => '2026-07-03 10:00:00',
        ]);

        $documents = new MusicSearchableProvider($this->connection)->documents();

        self::assertCount(2, $documents);
        $titles = array_map(static fn ($d): string => $d->title, $documents);
        sort($titles);
        self::assertSame(['Abbey Road', 'The Wall'], $titles);
        foreach ($documents as $document) {
            self::assertSame(SearchResultType::MUSIC, $document->type);
            self::assertSame('/music', $document->url);
        }
    }
}
