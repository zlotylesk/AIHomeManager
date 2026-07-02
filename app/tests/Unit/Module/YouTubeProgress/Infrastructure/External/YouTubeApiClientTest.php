<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\YouTubeProgress\Infrastructure\External;

use App\Module\YouTubeProgress\Infrastructure\External\YouTubeApiClient;
use App\Shared\Security\GoogleTokenProviderInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YouTubeApiClientTest extends TestCase
{
    /** @var list<string> */
    private array $requestedUrls = [];

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses, ?string $accessToken = 'ya29.token'): YouTubeApiClient
    {
        $this->requestedUrls = [];
        $queue = $responses;

        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$queue): MockResponse {
            $this->requestedUrls[] = $url;
            $response = array_shift($queue);
            self::assertNotNull($response, 'YouTubeApiClient made an unexpected extra HTTP call to '.$url);

            return $response;
        });

        $tokenRepo = $this->createStub(GoogleTokenProviderInterface::class);
        $tokenRepo->method('get')->willReturn(null === $accessToken ? null : ['access_token' => $accessToken]);

        return new YouTubeApiClient($httpClient, $tokenRepo);
    }

    /**
     * @param list<string> $videoIds
     */
    private function playlistItemsResponse(array $videoIds, ?string $nextPageToken = null): MockResponse
    {
        $items = array_map(static fn (string $id): array => [
            'snippet' => [
                'publishedAt' => '2024-01-15T10:30:00Z',
                'resourceId' => ['kind' => 'youtube#video', 'videoId' => $id],
            ],
        ], $videoIds);

        $body = ['items' => $items];
        if (null !== $nextPageToken) {
            $body['nextPageToken'] = $nextPageToken;
        }

        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @param list<string> $videoIds
     */
    private function videosResponse(array $videoIds, string $duration = 'PT5M'): MockResponse
    {
        $items = array_map(static fn (string $id): array => [
            'id' => $id,
            'snippet' => ['title' => 'Title '.$id, 'channelTitle' => 'Channel '.$id],
            'contentDetails' => ['duration' => $duration],
        ], $videoIds);

        return new MockResponse(json_encode(['items' => $items], JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function countCalls(string $needle): int
    {
        return count(array_filter($this->requestedUrls, static fn (string $url): bool => str_contains($url, $needle)));
    }

    public function testFetchPlaylistVideosEmptyPlaylistReturnsEmptyArray(): void
    {
        $client = $this->client([$this->playlistItemsResponse([])]);

        self::assertSame([], $client->fetchPlaylistVideos('PL_empty'));

        self::assertSame(0, $this->countCalls('/videos'));
    }

    public function testFetchPlaylistVideosSinglePagePopulatesMetadata(): void
    {
        $client = $this->client([
            $this->playlistItemsResponse(['vid1']),
            $this->videosResponse(['vid1'], 'PT5M'),
        ]);

        $videos = $client->fetchPlaylistVideos('PL_one');

        self::assertCount(1, $videos);
        self::assertSame('vid1', $videos[0]->youtubeId);
        self::assertSame('Title vid1', $videos[0]->title);
        self::assertSame('Channel vid1', $videos[0]->channel);
        self::assertSame(300, $videos[0]->durationSeconds);
        self::assertSame('2024-01-15T10:30:00+00:00', $videos[0]->publishedAt->format('c'));
    }

    public function testFetchPlaylistVideosFollowsPageToken(): void
    {
        $client = $this->client([
            $this->playlistItemsResponse(['v1'], 'PAGE_2'),
            $this->playlistItemsResponse(['v2']),
            $this->videosResponse(['v1', 'v2']),
        ]);

        $videos = $client->fetchPlaylistVideos('PL_paged');

        self::assertCount(2, $videos);

        self::assertSame(2, $this->countCalls('/playlistItems'));
    }

    public function testFetchPlaylistVideosBatchesVideoIdsInto50(): void
    {
        $ids = array_map(static fn (int $i): string => 'v'.$i, range(1, 75));
        $page1 = array_slice($ids, 0, 50);
        $page2 = array_slice($ids, 50);

        $client = $this->client([
            $this->playlistItemsResponse($page1, 'PAGE_2'),
            $this->playlistItemsResponse($page2),
            $this->videosResponse($page1),
            $this->videosResponse($page2),
        ]);

        $videos = $client->fetchPlaylistVideos('PL_big');

        self::assertCount(75, $videos);

        self::assertSame(2, $this->countCalls('/videos'));
    }

    public function testParsesDurationFromIso8601(): void
    {
        $client = $this->client([
            $this->playlistItemsResponse(['vid1']),
            $this->videosResponse(['vid1'], 'PT1H2M3S'),
        ]);

        $videos = $client->fetchPlaylistVideos('PL_dur');

        self::assertSame(3723, $videos[0]->durationSeconds);
    }

    public function testThrowsOnMissingToken(): void
    {
        $client = $this->client([], accessToken: null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No Google OAuth token');

        $client->fetchPlaylistVideos('PL_no_token');
    }
}
