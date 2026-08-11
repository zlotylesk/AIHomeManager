<?php

declare(strict_types=1);

namespace App\Monitoring;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The last metre: turns "something is wrong" into "the owner knows".
 *
 * Detection was never the missing part. The health probe has known the state of
 * six components for a long time, the backup logs its own failures and the
 * dead-letter queue counts its own depth — and every one of those went to a
 * place nobody was watching. This class is the piece that reads them on a timer
 * and sends an e-mail.
 *
 * Three rules govern what gets said:
 *
 *  - **Announce transitions, not state.** Probes report everything wrong on
 *    every run; a failure standing for a week must cost one e-mail, not two
 *    thousand, or the channel becomes noise and the next real alert is ignored.
 *  - **Getting worse is news.** A disk at 82 % and a disk at 96 % are different
 *    situations, so a severity that rises is announced again even though the
 *    key is already standing.
 *  - **A blind probe is not a healthy one.** When a probe throws, its stored
 *    alerts are held rather than reported recovered — otherwise the first thing
 *    a broken probe would do is send "all clear" for everything it used to
 *    watch. The failure itself becomes an alert, because a monitor that quietly
 *    stops monitoring is the exact pattern this exists to break.
 *
 * Delivery failure is not success: an alert nothing could deliver is left
 * un-recorded, so the next run treats it as new and tries again.
 */
final readonly class SystemMonitor
{
    /**
     * Namespace for "the probe itself is broken" alerts. Reserved — a probe must
     * not use it as its own {@see AlertProbeInterface::name()}, or a genuine
     * failure of that probe would be indistinguishable from its findings.
     */
    private const string PROBE_NAMESPACE = 'probe';

    /**
     * @param iterable<AlertProbeInterface>   $probes
     * @param iterable<AlertChannelInterface> $channels
     */
    public function __construct(
        private iterable $probes,
        private iterable $channels,
        private AlertStateStoreInterface $state,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function run(DateTimeImmutable $at): MonitorRunSummary
    {
        /** @var array<string, Alert> $current */
        $current = [];
        /** @var array<string, true> $blind */
        $blind = [];

        foreach ($this->probes as $probe) {
            $name = $probe->name();

            try {
                foreach ($probe->probe($at) as $alert) {
                    $namespaced = $alert->namespacedIn($name);
                    $current[$namespaced->key] = $namespaced;
                }
            } catch (Throwable $failure) {
                $blind[$name] = true;

                $this->logger->error('A monitoring probe failed.', [
                    'probe' => $name,
                    'exception' => $failure,
                ]);

                $meta = new Alert(
                    key: $name,
                    severity: AlertSeverity::CRITICAL,
                    title: sprintf('The "%s" monitoring probe is failing', $name),
                    detail: sprintf(
                        "%s: %s\n\nNothing this probe watches is being monitored while this stands.",
                        $failure::class,
                        $failure->getMessage(),
                    ),
                )->namespacedIn(self::PROBE_NAMESPACE);

                $current[$meta->key] = $meta;
            }
        }

        return $this->reconcile($current, $blind, $at);
    }

    /**
     * @param array<string, Alert> $current
     * @param array<string, true>  $blind
     */
    private function reconcile(array $current, array $blind, DateTimeImmutable $at): MonitorRunSummary
    {
        $stored = $this->state->load();
        /** @var array<string, StoredAlert> $next */
        $next = [];
        /** @var list<string> $announced */
        $announced = [];

        foreach ($current as $key => $alert) {
            $previous = $stored[$key] ?? null;

            if (null !== $previous && !$alert->severity->outranks($previous->severity)) {
                // Already announced, so nothing is said — but the title tracks
                // the latest reading rather than the first. The recovery e-mail
                // is written from what is stored here, and "RESOLVED — the
                // backup is 26 h old" would understate an outage that had by
                // then reached a fortnight.
                $next[$key] = new StoredAlert($key, $previous->severity, $alert->title, $previous->since);

                continue;
            }

            $since = null !== $previous ? $previous->since : $at;
            $delivered = $this->announce(new AlertNotice(
                key: $key,
                transition: null === $previous ? AlertTransition::FIRING : AlertTransition::ESCALATED,
                severity: $alert->severity,
                title: $alert->title,
                detail: $alert->detail,
                at: $at,
                since: $since,
            ));

            if ($delivered) {
                $announced[] = $key;
                $next[$key] = new StoredAlert($key, $alert->severity, $alert->title, $since);

                continue;
            }

            // Undelivered. Keeping the previous record (or none at all) is what
            // makes the next run try again instead of recording a silence as if
            // it had been an announcement.
            if (null !== $previous) {
                $next[$key] = $previous;
            }
        }

        foreach ($stored as $key => $previous) {
            if (isset($current[$key])) {
                continue;
            }

            if (isset($blind[$this->namespaceOf($key)])) {
                $next[$key] = $previous;

                continue;
            }

            $delivered = $this->announce(new AlertNotice(
                key: $key,
                transition: AlertTransition::RESOLVED,
                severity: $previous->severity,
                title: $previous->title,
                detail: '',
                at: $at,
                since: $previous->since,
            ));

            if ($delivered) {
                $announced[] = $key;

                continue;
            }

            $next[$key] = $previous;
        }

        $this->state->save($next);

        return new MonitorRunSummary($announced, array_keys($next));
    }

    /**
     * True when at least one channel accepted the notice. One channel failing
     * must not stop the others, but nothing getting through means nothing was
     * announced.
     */
    private function announce(AlertNotice $notice): bool
    {
        $delivered = false;

        foreach ($this->channels as $channel) {
            try {
                $channel->send($notice);
                $delivered = true;
            } catch (Throwable $failure) {
                $this->logger->error('Could not deliver an operational alert.', [
                    'alert' => $notice->key,
                    'transition' => $notice->transition->value,
                    'channel' => $channel::class,
                    'exception' => $failure,
                ]);
            }
        }

        return $delivered;
    }

    private function namespaceOf(string $key): string
    {
        $separator = strpos($key, ':');

        return false === $separator ? $key : substr($key, 0, $separator);
    }
}
