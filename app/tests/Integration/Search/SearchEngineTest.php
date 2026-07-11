<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Infrastructure\Engine\FulltextSearchEngine;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SearchEngineTest extends KernelTestCase
{
    private Connection $connection;
    private FulltextSearchEngine $engine;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->engine = new FulltextSearchEngine($this->connection);

        $this->connection->executeStatement('DELETE FROM search_documents');
        // "space" appears in b2 (title + content, x3) and s1 (title, x1) — a
        // 2-of-6 minority, so FULLTEXT natural-language mode keeps it a match.
        $this->seed(SearchResultType::BOOK, 'b1', 'Dune', 'Frank Herbert desert planet', '/books');
        $this->seed(SearchResultType::BOOK, 'b2', 'Space Odyssey', 'a space voyage through space', '/books');
        $this->seed(SearchResultType::SERIES, 's1', 'Deep Space Nine', 'orbital station drama', '/series');
        $this->seed(SearchResultType::ARTICLE, 'a1', 'Cooking Pasta', 'italian cuisine tips', '/articles');
        $this->seed(SearchResultType::TASK, 't1', 'Buy groceries', '', '/tasks');
        $this->seed(SearchResultType::MUSIC, 'm1', 'Jazz Session', 'live studio recording', '/music');
    }

    private function seed(SearchResultType $type, string $id, string $title, string $content, string $url): void
    {
        $this->connection->insert('search_documents', [
            'type' => $type->value, 'source_id' => $id, 'title' => $title, 'content' => $content, 'url' => $url,
        ]);
    }

    public function testRanksMatchesByRelevance(): void
    {
        $results = $this->engine->search(new SearchQuery('space'));

        self::assertCount(2, $results);
        self::assertSame('b2', $results[0]->id, 'The document mentioning the term three times ranks first.');
        self::assertSame('s1', $results[1]->id);
    }

    public function testFiltersByType(): void
    {
        $results = $this->engine->search(new SearchQuery('space', SearchResultType::BOOK));

        self::assertCount(1, $results);
        self::assertSame(SearchResultType::BOOK, $results[0]->type);
        self::assertSame('b2', $results[0]->id);
    }

    public function testPaginatesRankedResults(): void
    {
        $page1 = $this->engine->search(new SearchQuery('space', null, 1, 1));
        $page2 = $this->engine->search(new SearchQuery('space', null, 2, 1));

        self::assertCount(1, $page1);
        self::assertCount(1, $page2);
        self::assertSame('b2', $page1[0]->id);
        self::assertSame('s1', $page2[0]->id);
    }

    public function testReturnsEmptyWhenNothingMatches(): void
    {
        self::assertSame([], $this->engine->search(new SearchQuery('nonexistentqwerty')));
    }

    public function testNormalizesRowToSearchResult(): void
    {
        $results = $this->engine->search(new SearchQuery('dune'));

        self::assertCount(1, $results);
        $result = $results[0];
        self::assertSame(SearchResultType::BOOK, $result->type);
        self::assertSame('b1', $result->id);
        self::assertSame('Dune', $result->title);
        self::assertStringContainsString('Frank Herbert', $result->snippet);
        self::assertSame('/books', $result->url);
    }
}
