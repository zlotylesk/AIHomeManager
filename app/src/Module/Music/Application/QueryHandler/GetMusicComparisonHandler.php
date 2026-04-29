<?php

declare(strict_types=1);

namespace App\Module\Music\Application\QueryHandler;

use App\Module\Music\Application\DTO\MusicComparisonDTO;
use App\Module\Music\Application\Query\GetMusicComparison;
use App\Module\Music\Application\Service\AlbumNormalizer;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use App\Module\Music\Domain\Port\VinylCollectionInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetMusicComparisonHandler
{
    private const DUSTY_SHELF_LIMIT = 500;
    private const CACHE_TTL = 3600;

    public function __construct(
        private MusicListeningHistoryInterface $listeningHistory,
        private VinylCollectionInterface $vinylCollection,
        private \Redis $redis,
        private string $lastfmUsername,
        private string $discogsUsername,
    ) {}

    public function __invoke(GetMusicComparison $query): MusicComparisonDTO
    {
        $cacheKey = sprintf('music:comparison:%s:%s:%d', $this->lastfmUsername, $query->period, $query->limit);

        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return unserialize($cached);
        }

        $topAlbums = $this->listeningHistory->getTopAlbums($this->lastfmUsername, $query->period, $query->limit);
        $topAlbumsForDustyShelf = $this->listeningHistory->getTopAlbums($this->lastfmUsername, '1month', self::DUSTY_SHELF_LIMIT);
        $collection = $this->vinylCollection->getUserCollection($this->discogsUsername);

        $discogsKeys = [];
        foreach ($collection as $record) {
            $key = AlbumNormalizer::normalize($record->artist, $record->title);
            $discogsKeys[$key] = true;
        }

        $lastfmTopKeys = [];
        foreach ($topAlbumsForDustyShelf as $album) {
            $key = AlbumNormalizer::normalize($album->artist, $album->title);
            $lastfmTopKeys[$key] = true;
        }

        $ownedAndListened = [];
        $wantList = [];

        foreach ($topAlbums as $album) {
            $key = AlbumNormalizer::normalize($album->artist, $album->title);
            if (isset($discogsKeys[$key])) {
                $ownedAndListened[] = $album;
            } else {
                $wantList[] = $album;
            }
        }

        $dustyShelf = [];
        foreach ($collection as $record) {
            $key = AlbumNormalizer::normalize($record->artist, $record->title);
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

        $this->redis->setex($cacheKey, self::CACHE_TTL, serialize($dto));

        return $dto;
    }
}
