<?php

declare(strict_types=1);

namespace App\Module\Music\Application\DTO;

final readonly class MusicComparisonDTO
{
    public function __construct(
        /** @var AlbumDTO[] */
        public array $ownedAndListened,
        /** @var AlbumDTO[] */
        public array $wantList,
        /** @var VinylRecordDTO[] */
        public array $dustyShelf,
        public float $matchScore,
    ) {
    }
}
