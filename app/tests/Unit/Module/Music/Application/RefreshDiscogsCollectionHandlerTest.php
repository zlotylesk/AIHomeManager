<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Application;

use App\Module\Music\Application\Command\RefreshDiscogsCollection;
use App\Module\Music\Application\Handler\RefreshDiscogsCollectionHandler;
use App\Module\Music\Domain\Port\VinylCollectionLoaderInterface;
use PHPUnit\Framework\TestCase;

final class RefreshDiscogsCollectionHandlerTest extends TestCase
{
    public function testInvokeCallsLoaderWithUsernameFromCommand(): void
    {
        $loader = $this->createMock(VinylCollectionLoaderInterface::class);
        $loader->expects(self::once())
            ->method('fetchAndCacheCollection')
            ->with('testuser');

        $handler = new RefreshDiscogsCollectionHandler($loader);
        $handler(new RefreshDiscogsCollection('testuser'));
    }
}
