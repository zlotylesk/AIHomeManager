<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Infrastructure\Index\SearchIndexDefinition;
use App\Module\Search\Infrastructure\Index\SearchIndexManager;
use App\Tests\Support\AuthenticatedApiTrait;
use App\Tests\Support\ResetsSearchIndex;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use OpenSearch\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What a client sees after the cutover (HMAI-366, epic HMAI-359).
 *
 * {@see SearchEngineContractTestCase} proves the two engines agree; this proves
 * the *stack above them* does — controller, query bus, backend factory, the
 * HMAI-365 fallback and the normalizer — by driving the OpenSearch backend
 * through the real HTTP endpoints and asserting the same JSON contract
 * {@see SearchApiTest} asserts on FULLTEXT. A cutover changes relevance; it must
 * not change the shape of a response, because the PWA caches those responses and
 * the mobile client is being generated from that contract.
 *
 * **MySQL is deliberately left empty.** Since HMAI-365 an unreachable engine
 * degrades to FULLTEXT rather than failing, which would otherwise make this test
 * the easiest kind to fool: a broken OpenSearch read would be quietly answered
 * from the other backend and everything would still look green. With no rows in
 * `search_documents`, a non-empty result can only have come from the engine.
 */
final class SearchCutoverApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;
    use ResetsSearchIndex;

    private const string FLAG = 'SEARCH_ENGINE_BACKEND';

    private KernelBrowser $client;
    private Client $search;
    private SearchIndexDefinition $definition;
    private ?string $originalEnv = null;
    private ?string $originalServer = null;

    protected function setUp(): void
    {
        $env = $_ENV[self::FLAG] ?? null;
        $server = $_SERVER[self::FLAG] ?? null;
        $this->originalEnv = is_string($env) ? $env : null;
        $this->originalServer = is_string($server) ? $server : null;

        // Both superglobals: Dotenv populates each, and Symfony reads $_ENV
        // first and $_SERVER second.
        $_ENV[self::FLAG] = 'opensearch';
        $_SERVER[self::FLAG] = 'opensearch';

        $this->client = static::createClient();
        $this->authenticate($this->client);

        $container = static::getContainer();
        /** @var Client $search */
        $search = $container->get('app.search_client');
        /** @var SearchIndexDefinition $definition */
        $definition = $container->get(SearchIndexDefinition::class);
        $this->search = $search;
        $this->definition = $definition;

        /** @var Connection $connection */
        $connection = $container->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM search_documents');

        $this->resetSearchIndex($search, $definition);
        new SearchIndexManager($search, $definition)->createIfMissing();

        $this->index(SearchResultType::BOOK, 'b1', 'Dune', 'Frank Herbert desert planet', '/books');
        $this->index(SearchResultType::BOOK, 'b2', 'Space Odyssey', 'a space voyage through space', '/books');
        $this->index(SearchResultType::SERIES, 's1', 'Deep Space Nine', 'orbital station drama', '/series');
        $this->search->indices()->refresh(['index' => $definition->alias()]);
    }

    protected function tearDown(): void
    {
        $this->resetSearchIndex($this->search, $this->definition);

        if (null === $this->originalEnv) {
            unset($_ENV[self::FLAG]);
        } else {
            $_ENV[self::FLAG] = $this->originalEnv;
        }
        if (null === $this->originalServer) {
            unset($_SERVER[self::FLAG]);
        } else {
            $_SERVER[self::FLAG] = $this->originalServer;
        }

        parent::tearDown();
    }

    public function testTheApiAnswersFromTheEngineWithTheSameContract(): void
    {
        $this->client->request('GET', '/api/search?q=space');
        self::assertResponseIsSuccessful();

        $results = $this->jsonResponse($this->client);
        // Non-empty at all is the proof the engine answered: FULLTEXT has no
        // rows to fall back on.
        self::assertCount(2, $results);
        self::assertSame('b2', $results[0]['id'], 'The densest match still ranks first after the cutover.');
        self::assertSame('book', $results[0]['type']);
        self::assertSame('Space Odyssey', $results[0]['title']);
        self::assertSame('/books', $results[0]['url']);
        self::assertArrayHasKey('snippet', $results[0]);
    }

    public function testTheTypeFilterStillNarrowsAfterTheCutover(): void
    {
        $this->client->request('GET', '/api/search?q=space&type=book');
        self::assertResponseIsSuccessful();

        $results = $this->jsonResponse($this->client);
        self::assertCount(1, $results);
        self::assertSame('b2', $results[0]['id']);
    }

    public function testPaginationStillPagesAfterTheCutover(): void
    {
        $this->client->request('GET', '/api/search?q=space&perPage=1&page=2');
        self::assertResponseIsSuccessful();

        $results = $this->jsonResponse($this->client);
        self::assertCount(1, $results);
        self::assertSame('s1', $results[0]['id']);
    }

    public function testFacetsAnswerFromTheEngineToo(): void
    {
        $this->client->request('GET', '/api/search/facets?q=space');
        self::assertResponseIsSuccessful();

        self::assertSame(
            [['type' => 'book', 'count' => 1], ['type' => 'series', 'count' => 1]],
            $this->jsonResponse($this->client),
        );
    }

    public function testTypoToleranceIsWhatTheCutoverActuallyBuys(): void
    {
        $this->client->request('GET', '/api/search?q=dume');
        self::assertResponseIsSuccessful();

        // The one user-visible difference the flag is worth flipping for — and
        // simultaneously a second proof that FULLTEXT did not answer, since it
        // could never match this at all.
        $results = $this->jsonResponse($this->client);
        self::assertCount(1, $results);
        self::assertSame('b1', $results[0]['id']);
    }

    private function index(SearchResultType $type, string $id, string $title, string $content, string $url): void
    {
        $this->search->index([
            'index' => $this->definition->alias(),
            'id' => $type->value.':'.$id,
            'body' => [
                'type' => $type->value, 'source_id' => $id, 'title' => $title, 'content' => $content, 'url' => $url,
            ],
        ]);
    }
}
