<?php

declare(strict_types=1);

namespace App\Tests\Integration\Series;

use App\Module\Series\Application\Command\ImportWatchedShowsFromTrakt;
use App\Module\Series\Infrastructure\Persistence\TraktTokenRepositoryInterface;
use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * HMAI-184: POST /api/series/import/trakt is the GUI entry point for the
 * Trakt watched-shows import. It must (a) refuse with a readable 409 when no
 * Trakt token is stored so the front can prompt "Connect Trakt", and (b) when
 * connected, return 202 immediately while offloading the actual import onto the
 * async transport (the import is rate-limited + I/O bound and must never block
 * the request).
 */
final class ImportFromTraktApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        // One request then transport inspection — keep the kernel alive so the
        // InMemoryTransport that recorded the dispatch survives into the asserts.
        $this->client->disableReboot();

        static::getContainer()
            ->get(EntityManagerInterface::class)
            ->getConnection()
            ->executeStatement('TRUNCATE TABLE trakt_oauth_tokens');
    }

    public function testReturns409WhenTraktNotConnected(): void
    {
        $this->client->request('POST', '/api/series/import/trakt');

        self::assertResponseStatusCodeSame(409);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('/auth/trakt', $data['authUrl']);
        self::assertArrayHasKey('error', $data);

        self::assertSame(
            [],
            $this->asyncTransport()->getSent(),
            'No import job may be queued when Trakt is not connected — the worker could only fail it.',
        );
    }

    public function testReturns202AndDispatchesImportCommandWhenConnected(): void
    {
        $this->repository()->save([
            'access_token' => 'trakt-access-xyz',
            'token_type' => 'bearer',
            'expires_in' => 7776000,
            'refresh_token' => 'trakt-refresh-xyz',
            'scope' => 'public',
            'created_at' => 1700000000,
        ]);

        $this->client->request('POST', '/api/series/import/trakt');

        self::assertResponseStatusCodeSame(202);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('import_started', $data['status']);

        $imports = array_filter(
            $this->asyncTransport()->getSent(),
            static fn ($envelope) => $envelope->getMessage() instanceof ImportWatchedShowsFromTrakt,
        );
        self::assertCount(1, $imports, 'A connected import must enqueue exactly one ImportWatchedShowsFromTrakt on the async transport.');
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function repository(): TraktTokenRepositoryInterface
    {
        return static::getContainer()->get(TraktTokenRepositoryInterface::class);
    }
}
