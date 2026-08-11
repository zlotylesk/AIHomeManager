<?php

declare(strict_types=1);

namespace App\Tests\Support\Monitoring;

use App\Monitoring\Alert;
use App\Monitoring\AlertProbeInterface;
use DateTimeImmutable;
use RuntimeException;

/**
 * A probe whose findings the test dictates, and which can be made to break.
 *
 * Programmable between runs rather than fixed at construction, because most of
 * what {@see \App\Monitoring\SystemMonitor} does only shows up across two
 * sweeps: fire then stay quiet, fire then escalate, fire then recover.
 */
final class FakeAlertProbe implements AlertProbeInterface
{
    /** @var list<Alert> */
    private array $alerts = [];

    private ?RuntimeException $failure = null;

    public function __construct(private readonly string $name = 'fake')
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function probe(DateTimeImmutable $at): array
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->alerts;
    }

    public function reports(Alert ...$alerts): void
    {
        $this->alerts = array_values($alerts);
        $this->failure = null;
    }

    public function breaksWith(string $message): void
    {
        $this->failure = new RuntimeException($message);
    }
}
