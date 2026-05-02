<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/config/reference.php',
        __DIR__.'/var',
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
    )
    ->withParallel();
