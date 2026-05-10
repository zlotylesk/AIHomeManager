<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\External;

use App\Module\Music\Application\Command\RefreshDiscogsCollection;
use App\Module\Music\Application\DTO\VinylRecordDTO;
use App\Module\Music\Application\Exception\DiscogsApiException;
use App\Module\Music\Application\Exception\DiscogsAuthException;
use App\Module\Music\Application\Exception\DiscogsNotFoundException;
use App\Module\Music\Application\Exception\DiscogsRateLimitException;
use App\Module\Music\Application\Exception\DiscogsUnavailableException;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use App\Module\Music\Domain\Port\VinylCollectionLoaderInterface;
use App\Module\Music\Infrastructure\Persistence\DiscogsTokenRepositoryInterface;
use JsonException;
use Redis;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class DiscogsApiClient implements VinylCollectionInterface, VinylCollectionLoaderInterface
{
    private const string COLLECTION_URL = 'https://api.discogs.com/users/%s/collection/folders/0/releases';
    private const int CACHE_TTL = 21600;
    private const int PER_PAGE = 100;
    private const string USER_AGENT = 'AIHomeManager/1.0 +https://github.com/zlotylesk/AIHomeManager';

    public function __construct(
        private HttpClientInterface $httpClient,
        private Redis $redis,
        private DiscogsTokenRepositoryInterface $tokenRepository,
        private DiscogsOAuth1Signer $signer,
        private MessageBusInterface $commandBus,
        private string $consumerKey,
        private string $consumerSecret,
    ) {
    }

    /**
     * Read-only path: returns cached collection or schedules an async refresh and signals "not ready" to caller.
     *
     * @return VinylRecordDTO[]
     */
    public function getUserCollection(string $username): array
    {
        $cached = $this->redis->get($this->cacheKey($username));
        if (is_string($cached)) {
            try {
                return $this->decodeRecordsFromCache($cached);
            } catch (JsonException) {
                // Stale or corrupted cache entry — treat as miss.
            }
        }

        $this->commandBus->dispatch(new RefreshDiscogsCollection($username));

        throw new RuntimeException('Discogs collection is being refreshed. Try again in a minute.');
    }

    /**
     * Worker path: blocking fetch with rate-limit sleep, writes cache. Never call from a request handler.
     */
    public function fetchAndCacheCollection(string $username): void
    {
        $token = $this->tokenRepository->get();
        if (null === $token) {
            throw new DiscogsAuthException('Discogs not authorized. Visit /auth/discogs to connect.');
        }

        $records = $this->fetchAllPages($username, $token['oauth_token'], $token['oauth_token_secret']);

        $this->redis->setex(
            $this->cacheKey($username),
            self::CACHE_TTL,
            $this->encodeRecordsForCache($records),
        );
    }

    private function cacheKey(string $username): string
    {
        return sprintf('discogs:collection:%s', $username);
    }

    /** @param VinylRecordDTO[] $records */
    private function encodeRecordsForCache(array $records): string
    {
        return json_encode(
            array_map(
                static fn (VinylRecordDTO $record) => [
                    'artist' => $record->artist,
                    'title' => $record->title,
                    'year' => $record->year,
                    'format' => $record->format,
                    'discogsId' => $record->discogsId,
                ],
                $records,
            ),
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return VinylRecordDTO[] */
    private function decodeRecordsFromCache(string $json): array
    {
        $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return array_map(
            static fn (array $row) => new VinylRecordDTO(
                artist: (string) ($row['artist'] ?? ''),
                title: (string) ($row['title'] ?? ''),
                year: isset($row['year']) ? (int) $row['year'] : null,
                format: (string) ($row['format'] ?? ''),
                discogsId: (int) ($row['discogsId'] ?? 0),
            ),
            $rows,
        );
    }

    /** @return VinylRecordDTO[] */
    private function fetchAllPages(string $username, string $oauthToken, string $oauthTokenSecret): array
    {
        $url = sprintf(self::COLLECTION_URL, $username);
        $records = [];
        $page = 1;

        do {
            $queryParams = ['per_page' => self::PER_PAGE, 'page' => $page];

            $authHeader = $this->signer->buildAuthorizationHeader(
                'GET',
                $url,
                $this->consumerKey,
                $this->consumerSecret,
                $oauthTokenSecret,
                $oauthToken,
                $queryParams,
            );

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'query' => $queryParams,
                    'headers' => [
                        'Authorization' => $authHeader,
                        'User-Agent' => self::USER_AGENT,
                    ],
                ]);

                $data = $response->toArray();
            } catch (ClientExceptionInterface $e) {
                throw match ($e->getResponse()->getStatusCode()) {
                    401, 403 => new DiscogsAuthException('Discogs authorization failed — re-authorize at /auth/discogs.', 0, $e),
                    404 => new DiscogsNotFoundException(sprintf('Discogs user "%s" or collection not found.', $username), 0, $e),
                    429 => new DiscogsRateLimitException('Discogs rate limit exceeded — try again shortly.', 0, $e),
                    default => new DiscogsApiException('Discogs client error.', 0, $e),
                };
            } catch (ServerExceptionInterface|TransportExceptionInterface $e) {
                throw new DiscogsUnavailableException('Discogs API unavailable.', 0, $e);
            }

            foreach ($data['releases'] ?? [] as $item) {
                $records[] = $this->parseRelease($item);
            }

            $totalPages = (int) ($data['pagination']['pages'] ?? 1);
            ++$page;
        } while ($page <= $totalPages);

        return $records;
    }

    private function parseRelease(array $item): VinylRecordDTO
    {
        $info = $item['basic_information'] ?? [];

        $artist = implode(', ', array_map(
            fn ($a) => trim(preg_replace('/\s*\(\d+\)$/', '', $a['name'] ?? '')),
            $info['artists'] ?? []
        ));

        $format = $info['formats'][0]['name'] ?? 'Unknown';

        return new VinylRecordDTO(
            artist: $artist ?: 'Unknown Artist',
            title: $info['title'] ?? 'Unknown Title',
            year: isset($info['year']) && $info['year'] > 0 ? (int) $info['year'] : null,
            format: $format,
            discogsId: (int) ($item['id'] ?? 0),
        );
    }
}
