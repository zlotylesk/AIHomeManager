<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Infrastructure;

use App\Module\Music\Infrastructure\External\LastFmApiClient;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LastFmApiClientTest extends TestCase
{
    private Redis $redis;

    protected function setUp(): void
    {
        $this->redis = $this->createStub(Redis::class);
        $this->redis->method('get')->willReturn(false);
        $this->redis->method('setex')->willReturn(true);
    }

    private function makeApiResponse(array $albums): string
    {
        return json_encode([
            'topalbums' => [
                'album' => $albums,
            ],
        ]);
    }

    private function makeAlbum(string $artist, string $title, int $playCount, string $imageUrl = ''): array
    {
        return [
            'name' => $title,
            'playcount' => (string) $playCount,
            'artist' => ['name' => $artist],
            'image' => [
                ['size' => 'small', '#text' => ''],
                ['size' => 'medium', '#text' => ''],
                ['size' => 'large', '#text' => $imageUrl],
                ['size' => 'extralarge', '#text' => $imageUrl],
            ],
        ];
    }

    public function testReturnsAlbumDTOListFromApiResponse(): void
    {
        $json = $this->makeApiResponse([
            $this->makeAlbum('Radiohead', 'OK Computer', 150, 'https://img.last.fm/ok-computer.jpg'),
            $this->makeAlbum('Pink Floyd', 'The Wall', 120, ''),
        ]);

        $httpClient = new MockHttpClient(new MockResponse($json));
        $client = new LastFmApiClient($httpClient, $this->redis, 'test-api-key');

        $albums = $client->getTopAlbums('testuser', '1month', 10);

        self::assertCount(2, $albums);
        self::assertSame('Radiohead', $albums[0]->artist);
        self::assertSame('OK Computer', $albums[0]->title);
        self::assertSame(150, $albums[0]->playCount);
        self::assertSame('https://img.last.fm/ok-computer.jpg', $albums[0]->imageUrl);
    }

    public function testImageUrlIsNullWhenAllSizesEmpty(): void
    {
        $json = $this->makeApiResponse([$this->makeAlbum('Artist', 'Album', 10)]);
        $httpClient = new MockHttpClient(new MockResponse($json));
        $client = new LastFmApiClient($httpClient, $this->redis, 'test-api-key');

        $albums = $client->getTopAlbums('testuser', '1month', 10);

        self::assertNull($albums[0]->imageUrl);
    }

    public function testReturnsEmptyArrayWhenNoAlbums(): void
    {
        $json = json_encode(['topalbums' => ['album' => []]]);
        $httpClient = new MockHttpClient(new MockResponse($json));
        $client = new LastFmApiClient($httpClient, $this->redis, 'test-api-key');

        $albums = $client->getTopAlbums('testuser', '1month', 10);

        self::assertSame([], $albums);
    }

    public function testThrowsRuntimeExceptionOnTransportError(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));
        $client = new LastFmApiClient($httpClient, $this->redis, 'test-api-key');

        $this->expectException(RuntimeException::class);

        $client->getTopAlbums('testuser', '1month', 10);
    }

    public function testThrowsWhenApiKeyIsEmpty(): void
    {
        $httpClient = new MockHttpClient();
        $client = new LastFmApiClient($httpClient, $this->redis, '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Last.fm API key not configured');

        $client->getTopAlbums('testuser', '1month', 10);
    }

    public function testThrowsWhenApiKeyIsWhitespace(): void
    {
        // Regression for HMAI-84: a whitespace-only key (typical copy-paste
        // misconfig like `LASTFM_API_KEY=" "` in .env.local) must be treated as
        // "not configured", not silently passed to Last.fm as a malformed query.
        $httpClient = new MockHttpClient();
        $client = new LastFmApiClient($httpClient, $this->redis, "  \t\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Last.fm API key not configured');

        $client->getTopAlbums('testuser', '1month', 10);
    }

    public function testReturnsCachedResultWithoutHttpCall(): void
    {
        $cachePayload = json_encode([[
            'artist' => 'Artist',
            'title' => 'Album',
            'playCount' => 99,
            'imageUrl' => null,
        ]], JSON_THROW_ON_ERROR);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn($cachePayload);
        $redis->expects(self::never())->method('setex');

        $httpClient = new MockHttpClient();
        $client = new LastFmApiClient($httpClient, $redis, 'test-api-key');

        $result = $client->getTopAlbums('testuser', '1month', 10);

        self::assertCount(1, $result);
        self::assertSame('Artist', $result[0]->artist);
        self::assertSame('Album', $result[0]->title);
        self::assertSame(99, $result[0]->playCount);
        self::assertNull($result[0]->imageUrl);
    }

    public function testCorruptedCacheFallsBackToApiFetch(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn('not-valid-json');
        $redis->expects(self::once())->method('setex')->willReturn(true);

        $json = $this->makeApiResponse([
            $this->makeAlbum('Radiohead', 'OK Computer', 150, ''),
        ]);
        $httpClient = new MockHttpClient(new MockResponse($json));
        $client = new LastFmApiClient($httpClient, $redis, 'test-api-key');

        $albums = $client->getTopAlbums('testuser', '1month', 10);

        self::assertCount(1, $albums);
        self::assertSame('Radiohead', $albums[0]->artist);
    }
}
