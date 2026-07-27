<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\Port\SearchEngineInterface;
use App\Module\Search\Infrastructure\Engine\FulltextSearchEngine;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The MySQL FULLTEXT engine against the shared backend contract (HMAI-366).
 *
 * Everything asserted here lives in {@see SearchEngineContractTestCase}, run
 * unchanged against the other backend too — which is the point: the class holds
 * only what it takes to put documents in front of *this* engine. FULLTEXT adds
 * no scenarios of its own, because it is the baseline the contract describes.
 */
final class SearchEngineTest extends SearchEngineContractTestCase
{
    private Connection $connection;

    protected function prepareCorpus(): void
    {
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->connection->executeStatement('DELETE FROM search_documents');
    }

    protected function seed(SearchResultType $type, string $id, string $title, string $content, string $url): void
    {
        $this->connection->insert('search_documents', [
            'type' => $type->value, 'source_id' => $id, 'title' => $title, 'content' => $content, 'url' => $url,
        ]);
    }

    protected function createEngine(): SearchEngineInterface
    {
        return new FulltextSearchEngine($this->connection);
    }
}
