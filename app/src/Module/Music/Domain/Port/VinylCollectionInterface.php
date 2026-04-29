<?php

declare(strict_types=1);

namespace App\Module\Music\Domain\Port;

use App\Module\Music\Application\DTO\VinylRecordDTO;

interface VinylCollectionInterface
{
    /** @return VinylRecordDTO[] */
    public function getUserCollection(string $username): array;
}
