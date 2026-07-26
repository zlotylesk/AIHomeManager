<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Module\Search\Infrastructure\Index\SearchIndexDefinition;
use App\Module\Search\Infrastructure\Index\SearchIndexManager;
use App\Tests\Support\ResetsSearchIndex;
use OpenSearch\Client;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * HMAI-362: the operator-facing half of the index schema — provisioning has to
 * be a command someone can run on a fresh box or after a mapping change, the
 * way `doctrine:migrations` is for MySQL.
 *
 * The exit code and the message are the contract here: a deploy script reads the
 * former, and a human fixing a blocked alias reads the latter.
 */
final class SearchIndexCommandTest extends KernelTestCase
{
    use ResetsSearchIndex;

    private Client $client;
    private SearchIndexDefinition $definition;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        /** @var Client $client */
        $client = static::getContainer()->get('app.search_client');
        /** @var SearchIndexDefinition $definition */
        $definition = static::getContainer()->get(SearchIndexDefinition::class);

        $this->client = $client;
        $this->definition = $definition;
        $this->resetSearchIndex($client, $definition);

        $this->tester = new CommandTester(new Application($kernel)->find('app:search:index'));
    }

    protected function tearDown(): void
    {
        $this->resetSearchIndex($this->client, $this->definition);
        parent::tearDown();
    }

    public function testProvisionsTheIndexOnAFreshEngine(): void
    {
        $status = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString($this->definition->alias(), $this->tester->getDisplay());
        self::assertNotNull(new SearchIndexManager($this->client, $this->definition)->currentIndex());
    }

    public function testSaysNothingChangedOnASecondRun(): void
    {
        $this->tester->execute([]);
        $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $this->tester->getStatusCode());
        // Safe to run on every deploy: a provisioned engine is reported, not
        // rebuilt behind the operator's back.
        self::assertStringContainsString('already provisioned', $this->tester->getDisplay());
    }

    public function testReindexSwitchesTheAliasToANewIndex(): void
    {
        $this->tester->execute([]);
        $manager = new SearchIndexManager($this->client, $this->definition);
        $original = $manager->currentIndex();

        $status = $this->tester->execute(['--reindex' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertNotSame($original, $manager->currentIndex());
    }

    public function testReportsABlockedAliasAsAFailureInsteadOfAStackTrace(): void
    {
        $this->client->indices()->create(['index' => $this->definition->alias()]);

        $status = $this->tester->execute([]);

        self::assertSame(Command::FAILURE, $status, 'A deploy script has to be able to tell this went wrong.');
        // And the message has to carry the fix, since the engine's own wording
        // ("invalid alias name") explains nothing.
        self::assertStringContainsString('Delete it', $this->tester->getDisplay());
    }
}
