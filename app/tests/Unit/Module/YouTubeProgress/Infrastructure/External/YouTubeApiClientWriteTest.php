<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\YouTubeProgress\Infrastructure\External;

use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use App\Module\YouTubeProgress\Infrastructure\External\YouTubeApiClient;
use App\Shared\Security\GoogleTokenProviderInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YouTubeApiClientWriteTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: array<mixed>, auth: string}> */
    private array $requests = [];

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses, ?string $accessToken = 'ya29.token'): YouTubeApiClient
    {
        $this->requests = [];
        $queue = $responses;

        $httpClient = new MockHttpClient(
            /**
             * @param array<string, mixed> $options
             */
            function (string $method, string $url, array $options) use (&$queue): MockResponse {
                $this->requests[] = [
                    'method' => $method,
                    'url' => $url,
                    'body' => $this->decodeBody($options),
                    'auth' => $this->extractAuth($options),
                ];

                $response = array_shift($queue);
                self::assertNotNull($response, 'YouTubeApiClient made an unexpected extra HTTP call to '.$url);

                return $response;
            }
        );

        $tokenRepo = $this->createStub(GoogleTokenProviderInterface::class);
        $tokenRepo->method('get')->willReturn(null === $accessToken ? null : ['access_token' => $accessToken]);

        return new YouTubeApiClient($httpClient, $tokenRepo);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function decodeBody(array $options): array
    {
        $raw = $options['body'] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : ($options['json'] ?? null);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function extractAuth(array $options): string
    {
        $headers = $options['headers'] ?? [];
        if (!is_array($headers)) {
            return '';
        }

        foreach ($headers as $key => $value) {
            $line = is_string($key) ? $key.': '.(is_string($value) ? $value : '') : (is_string($value) ? $value : '');
            if (false !== stripos($line, 'authorization')) {
                return $line;
            }
        }

        return '';
    }

    private function jsonResponse(string $body): MockResponse
    {
        return new MockResponse($body, ['response_headers' => ['content-type' => 'application/json']]);
    }

    private function bodyValue(int $index, string ...$path): mixed
    {
        $cursor = $this->requests[$index]['body'];
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    public function testCreatePlaylistReturnsId(): void
    {
        $client = $this->client([$this->jsonResponse('{"id":"PLxxx"}')]);

        self::assertSame('PLxxx', $client->createPlaylist('My Session'));
    }

    public function testCreatePlaylistSendsCorrectSnippetAndStatus(): void
    {
        $client = $this->client([$this->jsonResponse('{"id":"PLxxx"}')]);

        $client->createPlaylist('AIHM Session 2026-06-04 21:35', false);

        self::assertStringContainsString('/playlists', $this->requests[0]['url']);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('AIHM Session 2026-06-04 21:35', $this->bodyValue(0, 'snippet', 'title'));
        self::assertSame('public', $this->bodyValue(0, 'status', 'privacyStatus'));
    }

    public function testCreatePlaylistDefaultsToPrivate(): void
    {
        $client = $this->client([$this->jsonResponse('{"id":"PLxxx"}')]);

        $client->createPlaylist('Untitled');

        self::assertSame('private', $this->bodyValue(0, 'status', 'privacyStatus'));
    }

    public function testCreatePlaylistThrowsOnMissingIdInResponse(): void
    {
        $client = $this->client([$this->jsonResponse('{}')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing id');

        $client->createPlaylist('No Id');
    }

    public function testAddVideosToPlaylistMakesSequentialCalls(): void
    {
        $client = $this->client([
            $this->jsonResponse('{"id":"item1"}'),
            $this->jsonResponse('{"id":"item2"}'),
            $this->jsonResponse('{"id":"item3"}'),
        ]);

        $client->addVideosToPlaylist('PLxxx', [
            new YoutubeVideoId('vid1'),
            new YoutubeVideoId('vid2'),
            new YoutubeVideoId('vid3'),
        ]);

        self::assertCount(3, $this->requests);
        foreach ($this->requests as $request) {
            self::assertSame('POST', $request['method']);
            self::assertStringContainsString('/playlistItems', $request['url']);
        }
        self::assertSame('PLxxx', $this->bodyValue(0, 'snippet', 'playlistId'));
    }

    public function testAddVideosToPlaylistEmptyListMakesNoCalls(): void
    {
        $client = $this->client([]);

        $client->addVideosToPlaylist('PLxxx', []);

        self::assertSame([], $this->requests);
    }

    public function testAddVideosToPlaylistPreservesOrder(): void
    {
        $client = $this->client([
            $this->jsonResponse('{}'),
            $this->jsonResponse('{}'),
            $this->jsonResponse('{}'),
        ]);

        $client->addVideosToPlaylist('PLxxx', [
            new YoutubeVideoId('aaa'),
            new YoutubeVideoId('bbb'),
            new YoutubeVideoId('ccc'),
        ]);

        self::assertSame('aaa', $this->bodyValue(0, 'snippet', 'resourceId', 'videoId'));
        self::assertSame('bbb', $this->bodyValue(1, 'snippet', 'resourceId', 'videoId'));
        self::assertSame('ccc', $this->bodyValue(2, 'snippet', 'resourceId', 'videoId'));
    }

    public function testWriteCallsUseBearerToken(): void
    {
        $client = $this->client([$this->jsonResponse('{"id":"PLxxx"}')]);

        $client->createPlaylist('Tokened');

        self::assertStringContainsString('Bearer ya29.token', $this->requests[0]['auth']);
    }
}
