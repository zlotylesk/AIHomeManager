<?php

declare(strict_types=1);

namespace App\Application\Scheduled;

/**
 * Ask every monitoring probe what is wrong and tell the owner about anything
 * that changed. Dispatched by {@see \App\Schedule} every five minutes.
 */
final readonly class MonitorSystemHealth
{
}
