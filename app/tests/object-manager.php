<?php

declare(strict_types=1);

use Doctrine\Persistence\ManagerRegistry;

require __DIR__.'/bootstrap.php';

$kernel = new App\Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();

/** @var ManagerRegistry $registry */
$registry = $kernel->getContainer()->get('doctrine');

return $registry->getManager();
