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
use JsonException;
use Redis;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetMusicComparisonHandler
{
    private const int DUSTY_SHELF_LIMIT = 500;
    private const int CACHE_TTL = 3600;
    private const string CACHE_VERSION = 'v2';

    public function __construct(
        private MusicListeningHistoryInterface $listeningHistory,
        private VinylCollectionInterface $vinylCollection,
        private AlbumNormalizer $normalizer,
        private Redis $redis,
        private string $lastfmUsername,
        private string $discogsUsername,
    ) {
    }

    public function __invoke(GetMusicComparison $query): MusicComparisonDTO
    {
        $cacheKey = sprintf(
            'music:comparison:%s:%s:%s:%d',
            self::CACHE_VERSION,
            $this->lastfmUsername,
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
        );

        $this->redis->setex($cacheKey, self::CACHE_TTL, $this->serializeDto($dto));

        return $dto;
    }

    private function serializeDto(MusicComparisonDTO $dto): string
    {
        return json_encode([
            'ownedAndListened' => array_map(self::albumToArray(...), $dto->ownedAndListened),
            'wantList' => array_map(self::albumToArray(...), $dto->wantList),
            'dustyShelf' => array_map(self::vinylToArray(...), $dto->dustyShelf),
            'matchScore' => $dto->matchScore,
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
            || !is_float($decoded['matchScore'] ?? null) && !is_int($decoded['matchScore'] ?? null)) {
            return null;
        }

        $owned = self::mapAlbums($decoded['ownedAndListened']);
        $want = self::mapAlbums($decoded['wantList']);
        $dusty = self::mapVinyls($decoded['dustyShelf']);

        if (null === $owned || null === $want || null === $dusty) {
            return null;
        }

        return new MusicComparisonDTO(
            ownedAndListened: $owned,
            wantList: $want,
            dustyShelf: $dusty,
            matchScore: (float) $decoded['matchScore'],
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
