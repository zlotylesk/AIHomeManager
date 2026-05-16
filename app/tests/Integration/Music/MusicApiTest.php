<?php

declare(strict_types=1);

namespace App\Tests\Integration\Music;

use App\Module\Music\Application\DTO\AlbumDTO;
use App\Module\Music\Application\DTO\VinylRecordDTO;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Redis;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MusicApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);

        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement('TRUNCATE TABLE discogs_oauth_tokens');
    }

    /**
     * Mocks the two external-music ports and pins them into the test container.
     * Happy-path tests need to bypass the real Last.fm/Discogs HTTP clients —
     * those return 503 in tests because API keys / Discogs tokens are absent.
     *
     * Must reboot-disable so the overrides survive the controller sub-request.
     *
     * @param list<AlbumDTO>       $topAlbums
     * @param list<VinylRecordDTO> $collection
     *
     * @return array{0: MusicListeningHistoryInterface&MockObject, 1: VinylCollectionInterface&MockObject}
     */
    private function installMusicPortMocks(array $topAlbums = [], array $collection = []): array
    {
        $this->client->disableReboot();

        $lastfm = $this->createMock(MusicListeningHistoryInterface::class);
        $lastfm->method('getTopAlbums')->willReturn($topAlbums);

        $discogs = $this->createMock(VinylCollectionInterface::class);
        $discogs->method('getUserCollection')->willReturn($collection);

        self::getContainer()->set(MusicListeningHistoryInterface::class, $lastfm);
        self::getContainer()->set(VinylCollectionInterface::class, $discogs);

        // The comparison handler caches by username — clear the test-env key
        // (empty usernames in .env.test) so a prior run's payload can't satisfy
        // this request before our port mocks are consulted.
        /** @var Redis $redis */
        $redis = self::getContainer()->get('app.redis');
        foreach ($redis->keys('music:comparison:*') as $key) {
            $redis->del($key);
        }

        return [$lastfm, $discogs];
    }

    public function testTopAlbumsWithInvalidPeriodReturns422(): void
    {
        $this->client->request('GET', '/api/music/top-albums?period=invalid');

        self::assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testTopAlbumsWithMissingApiKeyReturns503(): void
    {
        $this->client->request('GET', '/api/music/top-albums?period=1month');

        self::assertResponseStatusCodeSame(503);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('not configured', $data['error']);
    }

    public function testComparisonWithInvalidPeriodReturns422(): void
    {
        $this->client->request('GET', '/api/music/comparison?period=badvalue');

        self::assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testCollectionWhenCacheEmptyReturns503AndSchedulesRefresh(): void
    {
        $this->client->request('GET', '/api/music/collection');

        self::assertResponseStatusCodeSame(503);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertStringContainsString('being refreshed', strtolower((string) $data['error']));
    }

    public function testComparisonWhenDiscogsCacheEmptyReturns503(): void
    {
        $this->client->request('GET', '/api/music/comparison?period=1month&limit=5');

        self::assertResponseStatusCodeSame(503);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testTopAlbumsDefaultPeriodIsAccepted(): void
    {
        $this->client->request('GET', '/api/music/top-albums');

        // 503 because no API key configured — but not 422 (period is valid default)
        self::assertNotSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testTopAlbumsReturnsArrayWithExpectedFields(): void
    {
        $this->installMusicPortMocks(topAlbums: [
            new AlbumDTO('Pink Floyd', 'The Wall', 200, 'https://img.example/wall.jpg'),
            new AlbumDTO('Radiohead', 'OK Computer', 150, null),
        ]);

        $this->client->request('GET', '/api/music/top-albums?period=1month&limit=10');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertCount(2, $data);
        self::assertSame(
            ['artist', 'title', 'playCount', 'imageUrl'],
            array_keys($data[0])
        );
        self::assertSame('Pink Floyd', $data[0]['artist']);
        self::assertSame('The Wall', $data[0]['title']);
        self::assertSame(200, $data[0]['playCount']);
        self::assertSame('https://img.example/wall.jpg', $data[0]['imageUrl']);
        self::assertNull($data[1]['imageUrl']);
    }

    public function testCollectionReturnsArrayWithExpectedFields(): void
    {
        $this->installMusicPortMocks(collection: [
            new VinylRecordDTO('Pink Floyd', 'The Wall', 1979, 'Vinyl', 12345),
            new VinylRecordDTO('Unknown', 'No Year', null, 'CD', 67890),
        ]);

        $this->client->request('GET', '/api/music/collection');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertCount(2, $data);
        self::assertSame(
            ['artist', 'title', 'year', 'format', 'discogsId'],
            array_keys($data[0])
        );
        self::assertSame('Pink Floyd', $data[0]['artist']);
        self::assertSame(1979, $data[0]['year']);
        self::assertSame(12345, $data[0]['discogsId']);
        self::assertNull($data[1]['year']);
    }

    public function testComparisonWithOwnedAndListenedReturnsCorrectStructure(): void
    {
        // The handler calls getTopAlbums twice (the requested window + the
        // 1month/500-limit dustyShelf pull). The mock returns the same list
        // for both since `willReturn` is invocation-agnostic.
        $this->installMusicPortMocks(
            topAlbums: [
                new AlbumDTO('Pink Floyd', 'The Wall', 200, null),
                new AlbumDTO('Radiohead', 'OK Computer', 150, null),
            ],
            collection: [
                new VinylRecordDTO('Pink Floyd', 'The Wall', 1979, 'Vinyl', 1),
                new VinylRecordDTO('Forgotten Band', 'Forgotten Album', 1980, 'Vinyl', 2),
            ],
        );

        $this->client->request('GET', '/api/music/comparison?period=1month&limit=2');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame(
            ['matchScore', 'ownedAndListened', 'wantList', 'dustyShelf'],
            array_keys($data)
        );
        // 1 of the 2 requested albums (The Wall) is in the collection → 50%.
        // JSON-decoded as int when the float has no fractional part — compare loosely.
        self::assertEquals(50.0, $data['matchScore']);
        self::assertCount(1, $data['ownedAndListened']);
        self::assertSame('The Wall', $data['ownedAndListened'][0]['title']);
        self::assertCount(1, $data['wantList']);
        self::assertSame('OK Computer', $data['wantList'][0]['title']);
        // Forgotten Album is in the collection but not in the lastfm 1month top —
        // it lands on the dustyShelf.
        self::assertCount(1, $data['dustyShelf']);
        self::assertSame('Forgotten Album', $data['dustyShelf'][0]['title']);
    }
}
