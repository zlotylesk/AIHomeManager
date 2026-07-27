<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The behaviour both search backends must share (HMAI-366, epic HMAI-359).
 *
 * The premise of the whole epic is that FULLTEXT and OpenSearch are
 * interchangeable behind the port — so flipping `SEARCH_ENGINE_BACKEND` changes
 * relevance, never what the API returns or what the UI renders. Until now that
 * was maintained by **hand-mirroring** two test classes document for document,
 * which nothing enforced: a scenario added to one side, or a shared corpus
 * edited on one side only, would silently leave the cutover unproven exactly
 * where it was newly at risk.
 *
 * Making the contract an abstract case fixes the direction of the guarantee.
 * Adding a case here obliges **both** backends to satisfy it, and a backend that
 * cannot is a cutover blocker rather than a missing test nobody wrote.
 *
 * What stays out of here is capability, not contract: typo tolerance, Polish
 * stemming and diacritic folding are the reasons to prefer OpenSearch and MySQL
 * has no equivalent, so they live in the OpenSearch subclass. The line is
 * "would a user notice this changed when the flag flips" — ranking order,
 * pagination, filtering, the shape of a result and the facet rules all would.
 */
abstract class SearchEngineContractTestCase extends KernelTestCase
{
    protected SearchEngineInterface $engine;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->prepareCorpus();

        // "space" appears in b2 (title + content, x3) and s1 (title, x1) — a
        // 2-of-6 minority, so FULLTEXT natural-language mode keeps it a match.
        // The corpus lives here rather than in each subclass precisely because
        // the two backends have to be judged on identical data.
        $this->seed(SearchResultType::BOOK, 'b1', 'Dune', 'Frank Herbert desert planet', '/books');
        $this->seed(SearchResultType::BOOK, 'b2', 'Space Odyssey', 'a space voyage through space', '/books');
        $this->seed(SearchResultType::SERIES, 's1', 'Deep Space Nine', 'orbital station drama', '/series');
        $this->seed(SearchResultType::ARTICLE, 'a1', 'Cooking Pasta', 'italian cuisine tips', '/articles');
        $this->seed(SearchResultType::TASK, 't1', 'Buy groceries', '', '/tasks');
        $this->seed(SearchResultType::MUSIC, 'm1', 'Jazz Session', 'live studio recording', '/music');
        $this->commit();

        $this->engine = $this->createEngine();
    }

    /**
     * Empties the backend's corpus and makes sure it exists at all — the two
     * differ enough (a `DELETE`, versus dropping and provisioning an index) that
     * only the subclass can do it.
     */
    abstract protected function prepareCorpus(): void;

    abstract protected function seed(SearchResultType $type, string $id, string $title, string $content, string $url): void;

    abstract protected function createEngine(): SearchEngineInterface;

    /**
     * Makes the writes readable. A no-op on a database that just committed them;
     * a refresh on an engine where indexing is near-real-time rather than
     * synchronous. Tests that seed extra documents mid-run must call it.
     */
    protected function commit(): void
    {
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

    public function testNormalizesTheStoredDocumentToASearchResult(): void
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

    public function testTruncatesTheSnippetToTheSameLength(): void
    {
        $this->seed(SearchResultType::ARTICLE, 'a2', 'Longread', 'kaleidoscope '.str_repeat('a', 200), '/articles');
        $this->commit();

        $results = $this->engine->search(new SearchQuery('kaleidoscope'));

        self::assertCount(1, $results);
        // The snippet is rendered verbatim under every hit, so a backend cutting
        // it differently would visibly reflow the dropdown.
        self::assertSame(160, mb_strlen($results[0]->snippet));
    }

    /**
     * HMAI-364. The two documents carry the phrase exactly once each, in mirror
     * positions, so the combined (title, content) index scores them alike. What
     * separates them is only the title weight — and the titles are chosen so the
     * alphabetical tie-break would order them the *other* way round, which is
     * what makes this fail if either backend ever drops the boost.
     */
    public function testATitleMatchOutranksABodyMatch(): void
    {
        $this->seed(SearchResultType::BOOK, 'in-title', 'Zenit obserwacyjny', 'przewodnik testowy', '/books');
        $this->seed(SearchResultType::BOOK, 'in-body', 'Almanach testowy', 'zenit obserwacyjny', '/books');
        $this->commit();

        $results = $this->engine->search(new SearchQuery('zenit'));

        self::assertCount(2, $results);
        self::assertSame('in-title', $results[0]->id);
        self::assertSame('in-body', $results[1]->id);
    }

    public function testCountsMatchesPerType(): void
    {
        $facets = $this->engine->facets(new SearchQuery('space'));

        self::assertCount(2, $facets);
        self::assertSame(SearchResultType::BOOK, $facets[0]->type);
        self::assertSame(1, $facets[0]->count);
        self::assertSame(SearchResultType::SERIES, $facets[1]->type);
        self::assertSame(1, $facets[1]->count);
    }

    public function testFacetsIgnoreTheTypeFilter(): void
    {
        // Narrowed to books, but the counts still offer series — otherwise the
        // filter would hide the only route back out of it.
        $facets = $this->engine->facets(new SearchQuery('space', SearchResultType::BOOK));

        self::assertSame(
            [SearchResultType::BOOK, SearchResultType::SERIES],
            array_map(static fn ($facet) => $facet->type, $facets),
        );
    }

    public function testFacetsSpanTheWholeMatchSetNotTheRequestedPage(): void
    {
        $facets = $this->engine->facets(new SearchQuery('space', null, 2, 1));

        // One hit per page, second page requested — the counts describe all of
        // it regardless.
        self::assertSame(2, array_sum(array_map(static fn ($facet) => $facet->count, $facets)));
    }

    public function testFacetsAreEmptyWhenNothingMatches(): void
    {
        self::assertSame([], $this->engine->facets(new SearchQuery('nonexistentqwerty')));
    }

    public function testFacetsOmitTypesWithoutMatches(): void
    {
        $types = array_map(static fn ($facet) => $facet->type, $this->engine->facets(new SearchQuery('dune')));

        // Only books match "dune"; a zero-count entry for the other four would
        // invite the UI to offer a filter that leads nowhere.
        self::assertSame([SearchResultType::BOOK], $types);
    }
}
