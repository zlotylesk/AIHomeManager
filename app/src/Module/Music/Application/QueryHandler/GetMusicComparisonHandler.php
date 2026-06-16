<?php

declare(strict_types=1);

namespace App\Module\Music\Application\QueryHandler;

use App\Module\Music\Application\DTO\AlbumDTO;
use App\Module\Music\Application\DTO\MusicComparisonDTO;
use App\Module\Music\Application\DTO\VinylRecordDTO;
use App\Module\Music\Application\Query\GetMusicComparison;
use App\Module\Music\Application\Service\AlbumNormalizer;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use JsonException;
use Redis;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetMusicComparisonHandler
{
    private const int DUSTY_SHELF_LIMIT = 500;
    private const int CACHE_TTL = 3600;
    private const string CACHE_VERSION = 'v4';
    private const int RECENTLY_PLAYED_WINDOW_DAYS = 90;
    private const int RECENTLY_PLAYED_LIMIT = 100;

    public function __construct(
        private MusicListeningHistoryInterface $listeningHistory,
        private VinylCollectionInterface $vinylCollection,
        private AlbumNormalizer $normalizer,
        private Redis $redis,
        private Connection $connection,
        private string $lastfmUsername,
        private string $discogsUsername,
    ) {
    }

    public function __invoke(GetMusicComparison $query): MusicComparisonDTO
    {
        $cacheKey = sprintf(
            'music:comparison:%s:%s:%s:%s:%d',
            self::CACHE_VERSION,
            $this->lastfmUsername,
            $this->discogsUsername,
            $query->period,
            $query->limit,
        );

        $cached = $this->redis->get($cacheKey);
        if (is_string($cached)) {
            $hit = $this->deserializeDto($cached);
            if (null !== $hit) {
                return $hit;
            }
        }

        $topAlbums = $this->listeningHistory->getTopAlbums($this->lastfmUsername, $query->period, $query->limit);
        $topAlbumsForDustyShelf = $this->listeningHistory->getTopAlbums($this->lastfmUsername, '1month', self::DUSTY_SHELF_LIMIT);
        $collection = $this->vinylCollection->getUserCollection($this->discogsUsername);

        $discogsKeys = [];
        foreach ($collection as $record) {
            $key = $this->normalizer->normalize($record->artist, $record->title);
            $discogsKeys[$key] = true;
        }

        $lastfmTopKeys = [];
        foreach ($topAlbumsForDustyShelf as $album) {
            $key = $this->normalizer->normalize($album->artist, $album->title);
            $lastfmTopKeys[$key] = true;
        }

        $ownedAndListened = [];
        $wantList = [];

        foreach ($topAlbums as $album) {
            $key = $this->normalizer->normalize($album->artist, $album->title);
            if (isset($discogsKeys[$key])) {
                $ownedAndListened[] = $album;
            } else {
                $wantList[] = $album;
            }
        }

        $dustyShelf = [];
        foreach ($collection as $record) {
            $key = $this->normalizer->normalize($record->artist, $record->title);
            if (!isset($lastfmTopKeys[$key])) {
                $dustyShelf[] = $record;
            }
        }

        $matchScore = $query->limit > 0
            ? round(count($ownedAndListened) / $query->limit * 100, 1)
            : 0.0;

        $dto = new MusicComparisonDTO(
            ownedAndListened: $ownedAndListened,
            wantList: $wantList,
            dustyShelf: $dustyShelf,
            matchScore: $matchScore,
            recentlyPlayedNotOwned: $this->computeRecentlyPlayedNotOwned($discogsKeys),
        );

        $this->redis->setex($cacheKey, self::CACHE_TTL, $this->serializeDto($dto));

        return $dto;
    }

    /**
     * Albums played recently per the local listening history (HMAI-144) that are
     * absent from the Discogs collection — "you keep playing this, maybe buy it".
     * Sourced from our own DB, not Last.fm, so it works even when Last.fm is down.
     *
     * @param array<string, true> $discogsKeys normalized owned-album keys
     *
     * @return AlbumDTO[]
     */
    private function computeRecentlyPlayedNotOwned(array $discogsKeys): array
    {
        $since = new DateTimeImmutable(sprintf('-%d days', self::RECENTLY_PLAYED_WINDOW_DAYS))
            ->format('Y-m-d H:i:s');

        $rows = $this->connection->executeQuery(
            'SELECT artist, title, COUNT(*) AS plays
             FROM music_listening_sessions
             WHERE played_at >= :since
             GROUP BY artist, title
             ORDER BY plays DESC
             LIMIT :limit',
            ['since' => $since, 'limit' => self::RECENTLY_PLAYED_LIMIT],
            ['limit' => ParameterType::INTEGER],
        )->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $artist = (string) $row['artist'];
            $title = (string) $row['title'];
            if (isset($discogsKeys[$this->normalizer->normalize($artist, $title)])) {
                continue;
            }

            $result[] = new AlbumDTO(
                artist: $artist,
                title: $title,
                playCount: (int) $row['plays'],
                imageUrl: null,
            );
        }

        return $result;
    }

    private function serializeDto(MusicComparisonDTO $dto): string
    {
        return json_encode([
            'ownedAndListened' => array_map(self::albumToArray(...), $dto->ownedAndListened),
            'wantList' => array_map(self::albumToArray(...), $dto->wantList),
            'dustyShelf' => array_map(self::vinylToArray(...), $dto->dustyShelf),
            'matchScore' => $dto->matchScore,
            'recentlyPlayedNotOwned' => array_map(self::albumToArray(...), $dto->recentlyPlayedNotOwned),
        ], JSON_THROW_ON_ERROR);
    }

    private function deserializeDto(string $cached): ?MusicComparisonDTO
    {
        try {
            $decoded = json_decode($cached, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)
            || !is_array($decoded['ownedAndListened'] ?? null)
            || !is_array($decoded['wantList'] ?? null)
            || !is_array($decoded['dustyShelf'] ?? null)
            || !is_array($decoded['recentlyPlayedNotOwned'] ?? null)
            || !is_float($decoded['matchScore'] ?? null) && !is_int($decoded['matchScore'] ?? null)) {
            return null;
        }

        $owned = self::mapAlbums($decoded['ownedAndListened']);
        $want = self::mapAlbums($decoded['wantList']);
        $dusty = self::mapVinyls($decoded['dustyShelf']);
        $recentlyPlayed = self::mapAlbums(array_values($decoded['recentlyPlayedNotOwned']));

        if (null === $owned || null === $want || null === $dusty || null === $recentlyPlayed) {
            return null;
        }

        return new MusicComparisonDTO(
            ownedAndListened: $owned,
            wantList: $want,
            dustyShelf: $dusty,
            matchScore: (float) $decoded['matchScore'],
            recentlyPlayedNotOwned: $recentlyPlayed,
        );
    }

    /**
     * @return array{artist: string, title: string, playCount: int, imageUrl: ?string}
     */
    private static function albumToArray(AlbumDTO $album): array
    {
        return [
            'artist' => $album->artist,
            'title' => $album->title,
            'playCount' => $album->playCount,
            'imageUrl' => $album->imageUrl,
        ];
    }

    /**
     * @return array{artist: string, title: string, year: ?int, format: string, discogsId: int}
     */
    private static function vinylToArray(VinylRecordDTO $record): array
    {
        return [
            'artist' => $record->artist,
            'title' => $record->title,
            'year' => $record->year,
            'format' => $record->format,
            'discogsId' => $record->discogsId,
        ];
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<AlbumDTO>|null
     */
    private static function mapAlbums(array $items): ?array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)
                || !is_string($item['artist'] ?? null)
                || !is_string($item['title'] ?? null)
                || !is_int($item['playCount'] ?? null)
                || !(null === ($item['imageUrl'] ?? null) || is_string($item['imageUrl'] ?? null))) {
                return null;
            }
            $result[] = new AlbumDTO(
                artist: $item['artist'],
                title: $item['title'],
                playCount: $item['playCount'],
                imageUrl: $item['imageUrl'] ?? null,
            );
        }

        return $result;
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<VinylRecordDTO>|null
     */
    private static function mapVinyls(array $items): ?array
    {
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)
                || !is_string($item['artist'] ?? null)
                || !is_string($item['title'] ?? null)
                || !(null === ($item['year'] ?? null) || is_int($item['year'] ?? null))
                || !is_string($item['format'] ?? null)
                || !is_int($item['discogsId'] ?? null)) {
                return null;
            }
            $result[] = new VinylRecordDTO(
                artist: $item['artist'],
                title: $item['title'],
                year: $item['year'] ?? null,
                format: $item['format'],
                discogsId: $item['discogsId'],
            );
        }

        return $result;
    }
}
