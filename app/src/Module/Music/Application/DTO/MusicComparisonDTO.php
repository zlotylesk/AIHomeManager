<?php

declare(strict_types=1);

namespace App\Module\Music\Application\DTO;

use App\Module\Music\Domain\ReadModel\Album;
use App\Module\Music\Domain\ReadModel\VinylRecord;

final readonly class MusicComparisonDTO
{
    public function __construct(
        /** @var Album[] */
        public array $ownedAndListened,
        /** @var Album[] */
        public array $wantList,
        /** @var VinylRecord[] */
        public array $dustyShelf,
        public float $matchScore,
        /** @var Album[] Recently played from local history but not in the Discogs collection (HMAI-144). */
        public array $recentlyPlayedNotOwned = [],
    ) {
    }
}
