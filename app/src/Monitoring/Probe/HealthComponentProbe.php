<?php

declare(strict_types=1);

namespace App\Monitoring\Probe;

use App\Health\HealthChecker;
use App\Monitoring\Alert;
use App\Monitoring\AlertProbeInterface;
use App\Monitoring\AlertSeverity;
use DateTimeImmutable;

/**
 * Reads the health check that already knew everything and told nobody.
 *
 * `GET /api/health` has reported six components for a long time; what it never
 * had was a caller. This probe asks {@see HealthChecker} **in-process** rather
 * than over HTTP, which is not a shortcut: an HTTP call would add nginx, the
 * firewall and the network to the list of things that must work for the alert
 * about a broken thing to be produced, and would report those failures as the
 * application being down. The endpoint remains for external uptime monitors,
 * which is the job an HTTP probe is genuinely better at.
 *
 * The severity mapping is the health check's own distinction, kept intact:
 * `down` is something the system needs being gone, `degraded` is a component
 * that has a fallback or does not affect serving. That is also what makes disk
 * pressure escalate on its own — 80 % is a warning, 95 % is critical, and the
 * monitor announces the step up.
 */
final readonly class HealthComponentProbe implements AlertProbeInterface
{
    /**
     * The first thing to do per component, so the alert is actionable without
     * opening the runbook. Anything more belongs in `docs/operations.md`.
     */
    private const array RUNBOOK = [
        'mysql' => 'The database is unreachable. Check `make logs-mysql` and whether the container is running; nothing writes until it is back.',
        'redis' => 'Redis is unreachable. Rating averages, rate limiting and the read caches are degraded, and worker heartbeats stop being recorded.',
        'rabbitmq' => 'The broker is unreachable. Async commands are not being delivered; check `make logs-rabbitmq` and that its named volume and fixed hostname are intact.',
        'search' => 'The search engine is unreachable. Global search has already fallen back to MySQL FULLTEXT, so this is not user-visible — but the OpenSearch index is going stale.',
        'worker' => 'A Messenger worker has not beaten within five minutes. Check `docker compose ps` for messenger_worker and scheduler_worker; while this stands, imports, the search reindex, notifications and the nightly backup are all stopped.',
        'disk' => 'The disk is filling up. Above 95 % MySQL cannot flush or write binlogs. Backups under BACKUP_DIR are usually the largest thing worth pruning.',
    ];

    public function __construct(private HealthChecker $health)
    {
    }

    public function name(): string
    {
        return 'health';
    }

    public function probe(DateTimeImmutable $at): array
    {
        $alerts = [];

        foreach ($this->health->check() as $component => $status) {
            $severity = match ($status) {
                'down' => AlertSeverity::CRITICAL,
                'degraded' => AlertSeverity::WARNING,
                default => null,
            };

            if (null === $severity) {
                continue;
            }

            $alerts[] = new Alert(
                key: $component,
                severity: $severity,
                title: sprintf('Health component "%s" is %s', $component, $status),
                detail: self::RUNBOOK[$component] ?? sprintf('No runbook entry for the "%s" component yet.', $component),
            );
        }

        return $alerts;
    }
}
