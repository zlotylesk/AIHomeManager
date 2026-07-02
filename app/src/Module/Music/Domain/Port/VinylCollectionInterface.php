<?php

declare(strict_types=1);

namespace App\Module\Music\Domain\Port;

use App\Module\Music\Domain\ReadModel\VinylRecord;

interface VinylCollectionInterface
{
    /** @return VinylRecord[] */
    public function getUserCollection(string $username): array;
}
