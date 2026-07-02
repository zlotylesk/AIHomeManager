<?php

declare(strict_types=1);

namespace App\Module\Books\Domain\Port;

use App\Module\Books\Domain\ReadModel\BookMetadata;

interface BookMetadataProviderInterface
{
    public function getByIsbn(string $isbn): BookMetadata;
}
