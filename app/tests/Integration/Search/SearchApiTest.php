<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SearchApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('DELETE FROM search_documents');
        $this->seed(SearchResultType::BOOK, 'b1', 'Dune', 'Frank Herbert desert planet', '/books');
        $this->seed(SearchResultType::BOOK, 'b2', 'Space Odyssey', 'a space voyage through space', '/books');
        $this->seed(SearchResultType::SERIES, 's1', 'Deep Space Nine', 'orbital station drama', '/series');
    }

    private function seed(SearchResultType $type, string $id, string $title, string $content, string $url): void
    {
        $this->connection->insert('search_documents', [
            'type' => $type->value, 'source_id' => $id, 'title' => $title, 'content' => $content, 'url' => $url,
        ]);
    }

    public function testSearchReturnsRankedResults(): void
    {
        $this->client->request('GET', '/api/search?q=space');
        self::assertResponseIsSuccessful();

        $results = $this->jsonResponse($this->client);
        self::assertCount(2, $results);
        self::assertSame('b2', $results[0]['id']);
        self::assertSame('book', $results[0]['type']);
        self::assertSame('Space Odyssey', $results[0]['title']);
        self::assertSame('/books', $results[0]['url']);
        self::assertArrayHasKey('snippet', $results[0]);
    }

    public function testTypeFilterNarrowsResults(): void
    {
        $this->client->request('GET', '/api/search?q=space&type=book');
        self::assertResponseIsSuccessful();

        $results = $this->jsonResponse($this->client);
        self::assertCount(1, $results);
        self::assertSame('book', $results[0]['type']);
        self::assertSame('b2', $results[0]['id']);
    }

    public function testPaginationLimitsResults(): void
    {
        $this->client->request('GET', '/api/search?q=space&perPage=1&page=2');
        self::assertResponseIsSuccessful();

        $results = $this->jsonResponse($this->client);
        self::assertCount(1, $results);
        self::assertSame('s1', $results[0]['id']);
    }

    public function testEmptyResultIsAValidEmptyArray(): void
    {
        $this->client->request('GET', '/api/search?q=nonexistentqwerty');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonResponse($this->client));
    }

    public function testBlankPhraseReturns422(): void
    {
        $this->client->request('GET', '/api/search?q=');

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnknownTypeReturns422(): void
    {
        $this->client->request('GET', '/api/search?q=space&type=podcast');

        self::assertResponseStatusCodeSame(422);
    }

    public function testPerPageAboveMaxReturns422(): void
    {
        $this->client->request('GET', '/api/search?q=space&perPage=999');

        self::assertResponseStatusCodeSame(422);
    }

    public function testVersionedAndAliasRoutesBothResolve(): void
    {
        $this->client->request('GET', '/api/v1/search?q=dune');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonResponse($this->client));
    }

    public function testRejectsInvalidApiKey(): void
    {
        $this->client->setServerParameter('HTTP_X_API_KEY', 'wrong-key');
        $this->client->request('GET', '/api/search?q=dune');

        self::assertResponseStatusCodeSame(401);
    }
}
