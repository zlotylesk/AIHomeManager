<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Infrastructure\Engine\FallbackSearchEngine;
use App\Module\Search\Infrastructure\Engine\FulltextSearchEngine;
use App\Tests\Support\AuthenticatedApiTrait;
use App\Tests\Support\RecordingSearchEngine;
use App\Tests\Support\SpyLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HMAI-365: what a user sees while the search engine is down.
 *
 * The unit tests prove the decorator's rules; this proves they survive the whole
 * stack — controller, query bus, handler, serializer — against the **real**
 * FULLTEXT engine and real MySQL. The distinction matters because the failure
 * this guards against is an HTTP one: a 500 on every keystroke of the navbar
 * search box, from a component the module treats as optional infrastructure.
 */
final class SearchOutageResilienceTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;
    private Connection $connection;
    private SpyLogger $logger;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);

        $container = static::getContainer();
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();
        $this->connection->executeStatement('DELETE FROM search_documents');
        $this->connection->insert('search_documents', [
            'type' => SearchResultType::BOOK->value,
            'source_id' => 'b1',
            'title' => 'Diuna',
            'content' => 'Frank Herbert pustynna planeta',
            'url' => '/books',
        ]);

        // The engine the flag would have selected, wrapped exactly as the
        // factory wraps it — but with the primary permanently unreachable.
        $this->logger = new SpyLogger();
        $container->set(SearchEngineInterface::class, new FallbackSearchEngine(
            new RecordingSearchEngine('opensearch', broken: true),
            $container->get(FulltextSearchEngine::class),
            $this->logger,
        ));
    }

    public function testSearchStillAnswersWhileTheEngineIsDown(): void
    {
        $this->client->request('GET', '/api/search?q=diuna');

        // 200 with the FULLTEXT results, not a 500: an engine outage costs
        // relevance, never the feature.
        self::assertResponseIsSuccessful();
        $results = $this->jsonResponse($this->client);
        self::assertCount(1, $results);
        self::assertSame('b1', $results[0]['id']);
        self::assertSame('Diuna', $results[0]['title']);
    }

    public function testFacetsStillAnswerWhileTheEngineIsDown(): void
    {
        $this->client->request('GET', '/api/search/facets?q=diuna');

        // The navbar issues both reads together; a facets endpoint that 500s
        // while search degrades would still surface as a broken search box.
        self::assertResponseIsSuccessful();
        $facets = $this->jsonResponse($this->client);
        self::assertSame([['type' => 'book', 'count' => 1]], $facets);
    }

    public function testTheOutageIsRecordedRatherThanHiddenByTheDegrade(): void
    {
        $this->client->request('GET', '/api/search?q=diuna');

        // Serving the user is not the same as pretending nothing happened —
        // without this line an engine could stay dead for weeks unnoticed.
        $record = $this->logger->findByMessage('Search degraded to the fallback engine.');
        self::assertNotNull($record);
        self::assertSame('warning', $record['level']);
    }
}
