<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Handler;

use App\Module\Music\Application\Command\RefreshDiscogsCollection;
use App\Module\Music\Domain\Port\VinylCollectionLoaderInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RefreshDiscogsCollectionHandler
{
    public function __construct(
        private VinylCollectionLoaderInterface $loader,
    ) {
    }

    public function __invoke(RefreshDiscogsCollection $command): void
    {
        $this->loader->fetchAndCacheCollection($command->username);
    }
}
