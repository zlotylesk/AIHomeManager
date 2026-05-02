<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Infrastructure;

use App\Module\Music\Infrastructure\External\DiscogsApiClient;
use App\Module\Music\Infrastructure\External\DiscogsOAuth1Signer;
use App\Module\Music\Infrastructure\Persistence\DiscogsTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Redis;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DiscogsApiClientTest extends TestCase
{
    private Redis $redis;
    private DiscogsTokenRepositoryInterface $tokenRepo;
    private DiscogsOAuth1Signer $signer;

    protected function setUp(): void
    {
        $this->redis = $this->createStub(Redis::class);
        $this->redis->method('get')->willReturn(false);
        $this->redis->method('setex')->willReturn(true);

        $this->tokenRepo = $this->createStub(DiscogsTokenRepositoryInterface::class);
        $this->tokenRepo->method('get')->willReturn([
            'oauth_token' => 'test-token',
            'oauth_token_secret' => 'test-secret',
        ]);

        $this->signer = new DiscogsOAuth1Signer();
    }

    private function makeReleasePage(array $releases, int $page = 1, int $totalPages = 1): string
    {
        return json_encode([
            'pagination' => ['page' => $page, 'pages' => $totalPages, 'per_page' => 100],
            'releases' => $releases,
        ]);
    }

    private function makeRelease(string $artist, string $title, int $year, string $format, int $id): array
    {
        return [
            'id' => $id,
            'basic_information' => [
                'title' => $title,
                'year' => $year,
                'artists' => [['name' => $artist]],
                'formats' => [['name' => $format]],
            ],
        ];
    }

    public function testReturnsVinylRecordDTOsFromSinglePage(): void
    {
        $json = $this->makeReleasePage([
            $this->makeRelease('Pink Floyd', 'The Wall', 1979, 'Vinyl', 12345),
            $this->makeRelease('Radiohead', 'OK Computer', 1997, 'Vinyl', 67890),
        ]);

        $httpClient = new MockHttpClient(new MockResponse($json));
        $client = new DiscogsApiClient($httpClient, $this->redis, $this->tokenRepo, $this->signer, 'key', 'secret');

        $records = $client->getUserCollection('testuser');

        self::assertCount(2, $records);
        self::assertSame('Pink Floyd', $records[0]->artist);
        self::assertSame('The Wall', $records[0]->title);
        self::assertSame(1979, $records[0]->year);
        self::assertSame('Vinyl', $records[0]->format);
        self::assertSame(12345, $records[0]->discogsId);
    }

    public function testFetchesMultiplePagesAndCombinesResults(): void
    {
        $page1 = $this->makeReleasePage(
            [$this->makeRelease('Artist A', 'Album A', 2000, 'Vinyl', 1)],
            1,
            2
        );
        $page2 = $this->makeReleasePage(
            [$this->makeRelease('Artist B', 'Album B', 2001, 'CD', 2)],
            2,
            2
        );

        $httpClient = new MockHttpClient([new MockResponse($page1), new MockResponse($page2)]);
        $client = new DiscogsApiClient($httpClient, $this->redis, $this->tokenRepo, $this->signer, 'key', 'secret');

        $records = $client->getUserCollection('testuser');

        self::assertCount(2, $records);
        self::assertSame('Artist A', $records[0]->artist);
        self::assertSame('Artist B', $records[1]->artist);
    }

    public function testNullYearWhenZero(): void
    {
        $json = $this->makeReleasePage([$this->makeRelease('Artist', 'Album', 0, 'Vinyl', 1)]);
        $httpClient = new MockHttpClient(new MockResponse($json));
        $client = new DiscogsApiClient($httpClient, $this->redis, $this->tokenRepo, $this->signer, 'key', 'secret');

        $records = $client->getUserCollection('testuser');

        self::assertNull($records[0]->year);
    }

    public function testThrowsWhenNoTokenStored(): void
    {
        $tokenRepo = $this->createStub(DiscogsTokenRepositoryInterface::class);
        $tokenRepo->method('get')->willReturn(null);

        $client = new DiscogsApiClient(new MockHttpClient(), $this->redis, $tokenRepo, $this->signer, 'key', 'secret');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Discogs not authorized');

        $client->getUserCollection('testuser');
    }

    public function testReturnsCachedResultWithoutHttpCall(): void
    {
        $dto = new \App\Module\Music\Application\DTO\VinylRecordDTO('Artist', 'Album', 2000, 'Vinyl', 1);

        $redis = $this->createMock(Redis::class);
        $redis->method('get')->willReturn(serialize([$dto]));
        $redis->expects(self::never())->method('setex');

        $client = new DiscogsApiClient(new MockHttpClient(), $redis, $this->tokenRepo, $this->signer, 'key', 'secret');

        $records = $client->getUserCollection('testuser');

        self::assertCount(1, $records);
        self::assertSame('Album', $records[0]->title);
    }
}
