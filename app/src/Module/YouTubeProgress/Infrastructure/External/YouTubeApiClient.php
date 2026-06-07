<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Infrastructure\External;

use App\Module\Tasks\Infrastructure\Persistence\GoogleTokenRepositoryInterface;
use App\Module\YouTubeProgress\Application\DTO\VideoMetadata;
use App\Module\YouTubeProgress\Domain\Port\YouTubePlaylistReaderInterface;
use App\Module\YouTubeProgress\Domain\ValueObject\VideoDuration;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Read adapter for the YouTube Data API v3. Collects a playlist's video IDs via
 * paginated playlistItems.list, then enriches them with title/channel/duration
 * via batched videos.list (50 IDs per call). Throttled through the injected
 * app.youtube_http_client (RateLimitedHttpClient). Fail-fast: no retry on quota
 * or transport errors (follow-up ticket).
 */
final readonly class YouTubeApiClient implements YouTubePlaylistReaderInterface
{
    private const string PLAYLIST_ITEMS_URL = 'https://www.googleapis.com/youtube/v3/playlistItems';
    private const string VIDEOS_URL = 'https://www.googleapis.com/youtube/v3/videos';
    private const int PAGE_SIZE = 50;
    private const int VIDEOS_BATCH = 50;

    public function __construct(
        private HttpClientInterface $httpClient,
        private GoogleTokenRepositoryInterface $tokenRepository,
    ) {
    }

    public function fetchPlaylistVideos(string $playlistId): array
    {
        $token = $this->accessToken();

        // playlistItems carries the added-to-playlist timestamp and order;
        // videos.list carries title/channel/duration. Fetch the items first,
        // then enrich them with the batched metadata lookup.
        $items = $this->fetchAllPlaylistItems($playlistId, $token);
        if ([] === $items) {
            return [];
        }

        $metadataById = $this->fetchVideoMetadataInBatches(array_column($items, 'videoId'), $token);

        $videos = [];
        foreach ($items as $item) {
            $meta = $metadataById[$item['videoId']] ?? null;
            if (null === $meta) {
                // Video deleted or made private after being added — videos.list
                // omits it. Skip rather than fabricate empty metadata.
                continue;
            }

            $videos[] = new VideoMetadata(
                youtubeId: $item['videoId'],
                title: $meta['title'],
                channel: $meta['channel'],
                durationSeconds: $meta['durationSeconds'],
                publishedAt: new DateTimeImmutable($item['publishedAt']),
            );
        }

        return $videos;
    }

    private function accessToken(): string
    {
        $accessToken = $this->tokenRepository->get()['access_token'] ?? null;

        if (!is_string($accessToken) || '' === $accessToken) {
            throw new RuntimeException('No Google OAuth token available for YouTube API.');
        }

        return $accessToken;
    }

    /**
     * @return list<array{videoId: string, publishedAt: string}>
     */
    private function fetchAllPlaylistItems(string $playlistId, string $token): array
    {
        $items = [];
        $pageToken = null;

        do {
            $query = [
                'part' => 'snippet',
                'playlistId' => $playlistId,
                'maxResults' => self::PAGE_SIZE,
            ];
            if (null !== $pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $data = $this->httpClient->request('GET', self::PLAYLIST_ITEMS_URL, [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'query' => $query,
            ])->toArray();

            foreach ($data['items'] ?? [] as $entry) {
                $snippet = $entry['snippet'] ?? [];
                $videoId = $snippet['resourceId']['videoId'] ?? null;
                if (!is_string($videoId) || '' === $videoId) {
                    continue;
                }

                $items[] = [
                    'videoId' => $videoId,
                    'publishedAt' => (string) ($snippet['publishedAt'] ?? 'now'),
                ];
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while (null !== $pageToken);

        return $items;
    }

    /**
     * @param list<string> $videoIds
     *
     * @return array<string, array{title: string, channel: string, durationSeconds: int}>
     */
    private function fetchVideoMetadataInBatches(array $videoIds, string $token): array
    {
        $byId = [];

        foreach (array_chunk($videoIds, self::VIDEOS_BATCH) as $batch) {
            $data = $this->httpClient->request('GET', self::VIDEOS_URL, [
                'headers' => ['Authorization' => 'Bearer '.$token],
                'query' => [
                    'part' => 'snippet,contentDetails',
                    'id' => implode(',', $batch),
                    'maxResults' => self::VIDEOS_BATCH,
                ],
            ])->toArray();

            foreach ($data['items'] ?? [] as $entry) {
                $id = $entry['id'] ?? null;
                if (!is_string($id) || '' === $id) {
                    continue;
                }

                $snippet = $entry['snippet'] ?? [];
                $duration = (string) ($entry['contentDetails']['duration'] ?? 'PT0S');

                $byId[$id] = [
                    'title' => (string) ($snippet['title'] ?? ''),
                    'channel' => (string) ($snippet['channelTitle'] ?? ''),
                    'durationSeconds' => VideoDuration::fromIsoDuration($duration)->toSeconds(),
                ];
            }
        }

        return $byId;
    }
}
