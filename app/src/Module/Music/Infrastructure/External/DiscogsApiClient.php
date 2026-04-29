<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\External;

use App\Module\Music\Application\DTO\VinylRecordDTO;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use App\Module\Music\Infrastructure\Persistence\DiscogsTokenRepositoryInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class DiscogsApiClient implements VinylCollectionInterface
{
    private const COLLECTION_URL = 'https://api.discogs.com/users/%s/collection/folders/0/releases';
    private const CACHE_TTL = 21600;
    private const PER_PAGE = 100;
    private const USER_AGENT = 'AIHomeManager/1.0 +https://github.com/zlotylesk/AIHomeManager';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly \Redis $redis,
        private readonly DiscogsTokenRepositoryInterface $tokenRepository,
        private readonly DiscogsOAuth1Signer $signer,
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
    ) {}

    /** @return VinylRecordDTO[] */
    public function getUserCollection(string $username): array
    {
        $cacheKey = sprintf('discogs:collection:%s', $username);

        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return unserialize($cached);
        }

        $token = $this->tokenRepository->get();
        if ($token === null) {
            throw new \RuntimeException('Discogs not authorized. Visit /auth/discogs to connect.');
        }

        $records = $this->fetchAllPages($username, $token['oauth_token'], $token['oauth_token_secret']);

        $this->redis->setex($cacheKey, self::CACHE_TTL, serialize($records));

        return $records;
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
            } catch (TransportExceptionInterface $e) {
                throw new \RuntimeException('Discogs API unavailable.', 0, $e);
            }

            foreach ($data['releases'] ?? [] as $item) {
                $records[] = $this->parseRelease($item);
            }

            $totalPages = (int) ($data['pagination']['pages'] ?? 1);

            if ($page < $totalPages) {
                sleep(1);
            }

            $page++;
        } while ($page <= $totalPages);

        return $records;
    }

    private function parseRelease(array $item): VinylRecordDTO
    {
        $info = $item['basic_information'] ?? [];

        $artist = implode(', ', array_map(
            fn($a) => trim(preg_replace('/\s*\(\d+\)$/', '', $a['name'] ?? '')),
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
