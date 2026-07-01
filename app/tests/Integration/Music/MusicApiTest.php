<?php

declare(strict_types=1);

namespace App\Tests\Integration\Music;

use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use App\Module\Music\Domain\ReadModel\Album;
use App\Module\Music\Domain\ReadModel\VinylRecord;
use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $conn->executeStatement('TRUNCATE TABLE music_listening_sessions');
    }

    /**
     * Mocks the two external-music ports and pins them into the test container.
     * Happy-path tests need to bypass the real Last.fm/Discogs HTTP clients —
     * those return 503 in tests because API keys / Discogs tokens are absent.
     *
     * Must reboot-disable so the overrides survive the controller sub-request.
     *
     * @param list<Album>       $topAlbums
     * @param list<VinylRecord> $collection
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
        $data = $this->jsonResponse($this->client);
        self::assertArrayHasKey('error', $data);
    }

    public function testTopAlbumsWithMissingApiKeyReturns503(): void
    {
        $this->client->request('GET', '/api/music/top-albums?period=1month');

        self::assertResponseStatusCodeSame(503);
        $data = $this->jsonResponse($this->client);
        self::assertStringContainsString('not configured', $data['error']);
    }

    public function testComparisonWithInvalidPeriodReturns422(): void
    {
        $this->client->request('GET', '/api/music/comparison?period=badvalue');

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertArrayHasKey('error', $data);
    }

    public function testCollectionWhenCacheEmptyReturns503AndSchedulesRefresh(): void
    {
        $this->client->request('GET', '/api/music/collection');

        self::assertResponseStatusCodeSame(503);
        $data = $this->jsonResponse($this->client);
        self::assertStringContainsString('being refreshed', strtolower((string) $data['error']));
    }

    public function testComparisonWhenDiscogsCacheEmptyReturns503(): void
    {
        $this->client->request('GET', '/api/music/comparison?period=1month&limit=5');

        self::assertResponseStatusCodeSame(503);
        $data = $this->jsonResponse($this->client);
        self::assertArrayHasKey('error', $data);
    }

    public function testTopAlbumsDefaultPeriodIsAccepted(): void
    {
        $this->client->request('GET', '/api/music/top-albums');

        self::assertNotSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testTopAlbumsReturnsArrayWithExpectedFields(): void
    {
        $this->installMusicPortMocks(topAlbums: [
            new Album('Pink Floyd', 'The Wall', 200, 'https://img.example/wall.jpg'),
            new Album('Radiohead', 'OK Computer', 150, null),
        ]);

        $this->client->request('GET', '/api/music/top-albums?period=1month&limit=10');

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
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
            new VinylRecord('Pink Floyd', 'The Wall', 1979, 'Vinyl', 12345),
            new VinylRecord('Unknown', 'No Year', null, 'CD', 67890),
        ]);

        $this->client->request('GET', '/api/music/collection');

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
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

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidLimitProvider(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'zero' => ['0'];
        yield 'non-numeric' => ['abc'];
        yield 'decimal' => ['1.5'];
        yield 'scientific notation' => ['1e3'];
    }

    #[DataProvider('invalidLimitProvider')]
    public function testTopAlbumsRejectsInvalidLimit(string $limit): void
    {
        $this->client->request('GET', '/api/music/top-albums?period=1month&limit='.urlencode($limit));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertSame('Field "limit" must be a positive integer between 1 and 1000.', $data['error']);
    }

    public function testTopAlbumsRejectsLimitOverMax(): void
    {
        $this->client->request('GET', '/api/music/top-albums?period=1month&limit=1001');

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertSame('Field "limit" must be a positive integer between 1 and 1000.', $data['error']);
    }

    #[DataProvider('invalidLimitProvider')]
    public function testComparisonRejectsInvalidLimit(string $limit): void
    {
        $this->client->request('GET', '/api/music/comparison?period=1month&limit='.urlencode($limit));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertSame('Field "limit" must be a positive integer between 1 and 200.', $data['error']);
    }

    public function testComparisonRejectsLimitOverMax(): void
    {
        $this->client->request('GET', '/api/music/comparison?period=1month&limit=201');

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertSame('Field "limit" must be a positive integer between 1 and 200.', $data['error']);
    }

    public function testComparisonWithOwnedAndListenedReturnsCorrectStructure(): void
    {
        $this->installMusicPortMocks(
            topAlbums: [
                new Album('Pink Floyd', 'The Wall', 200, null),
                new Album('Radiohead', 'OK Computer', 150, null),
            ],
            collection: [
                new VinylRecord('Pink Floyd', 'The Wall', 1979, 'Vinyl', 1),
                new VinylRecord('Forgotten Band', 'Forgotten Album', 1980, 'Vinyl', 2),
            ],
        );

        $this->client->request('GET', '/api/music/comparison?period=1month&limit=2');

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
        self::assertSame(
            ['matchScore', 'ownedAndListened', 'wantList', 'dustyShelf', 'recentlyPlayedNotOwned'],
            array_keys($data)
        );

        self::assertEquals(50.0, $data['matchScore']);
        self::assertCount(1, $data['ownedAndListened']);
        self::assertSame('The Wall', $data['ownedAndListened'][0]['title']);
        self::assertCount(1, $data['wantList']);
        self::assertSame('OK Computer', $data['wantList'][0]['title']);

        self::assertCount(1, $data['dustyShelf']);
        self::assertSame('Forgotten Album', $data['dustyShelf'][0]['title']);
    }

    public function testCreateSessionPersistsAndReturns201(): void
    {
        $this->postSession([
            'artist' => 'Pink Floyd',
            'title' => 'The Wall',
            'playedAt' => '2026-05-20T10:00:00+00:00',
            'playCount' => 5,
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $this->jsonResponse($this->client);
        self::assertSame('Pink Floyd', $data['artist']);
        self::assertSame('The Wall', $data['title']);
        self::assertSame('manual', $data['source']);
        self::assertSame(5, $data['playCount']);
    }

    public function testCreateSessionRejectsMissingPlayedAt(): void
    {
        $this->postSession(['artist' => 'Pink Floyd', 'title' => 'The Wall']);

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertArrayHasKey('error', $data);
    }

    public function testCreateSessionRejectsEmptyArtist(): void
    {
        $this->postSession([
            'artist' => '   ',
            'title' => 'The Wall',
            'playedAt' => '2026-05-20T10:00:00+00:00',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateSessionRejectsInvalidSource(): void
    {
        $this->postSession([
            'artist' => 'Pink Floyd',
            'title' => 'The Wall',
            'playedAt' => '2026-05-20T10:00:00+00:00',
            'source' => 'bogus',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testHistoryReturnsPostedSessionsNewestFirst(): void
    {
        $this->postSession(['artist' => 'A', 'title' => 'Older', 'playedAt' => '2026-05-18T09:00:00+00:00']);
        $this->postSession(['artist' => 'B', 'title' => 'Newer', 'playedAt' => '2026-05-20T09:00:00+00:00']);

        $this->client->request('GET', '/api/music/history');

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
        self::assertCount(2, $data);
        self::assertSame(['id', 'artist', 'title', 'playedAt', 'source', 'playCount'], array_keys($data[0]));
        self::assertSame('Newer', $data[0]['title']);
        self::assertSame('Older', $data[1]['title']);
    }

    public function testHistoryIsIdempotentForDuplicatePost(): void
    {
        $payload = ['artist' => 'Pink Floyd', 'title' => 'The Wall', 'playedAt' => '2026-05-20T10:00:00+00:00'];
        $this->postSession($payload);
        $this->postSession($payload);

        $this->client->request('GET', '/api/music/history');

        $data = $this->jsonResponse($this->client);
        self::assertCount(1, $data);
    }

    public function testHistoryFiltersBySource(): void
    {
        $this->postSession(['artist' => 'A', 'title' => 'Manual', 'playedAt' => '2026-05-20T09:00:00+00:00', 'source' => 'manual']);
        $this->postSession(['artist' => 'B', 'title' => 'Scrobble', 'playedAt' => '2026-05-20T10:00:00+00:00', 'source' => 'lastfm_scrobble']);

        $this->client->request('GET', '/api/music/history?source=manual');

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
        self::assertCount(1, $data);
        self::assertSame('Manual', $data[0]['title']);
    }

    public function testHistoryRejectsInvalidSource(): void
    {
        $this->client->request('GET', '/api/music/history?source=bogus');

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postSession(array $payload): void
    {
        $this->client->request(
            'POST',
            '/api/music/sessions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($payload)
        );
    }
}
