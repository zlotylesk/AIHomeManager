<?php

declare(strict_types=1);

namespace App\Health;

use Redis;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * HMAI-418: records that a Messenger worker is alive, and reads it back.
 *
 * The health probe used to ask the broker whether it was reachable, which
 * answers a different question: RabbitMQ is perfectly happy with nobody
 * consuming, so a dead worker left the app reporting 200 while the queue grew
 * in silence. That is how the nightly backup stopped for six days unnoticed.
 *
 * Asking RabbitMQ for consumer counts would not have caught it either — the
 * scheduler runs on `scheduler_default`, a Symfony Scheduler transport that
 * never touches the broker, so the one worker whose death actually cost us
 * something is invisible from there. The workers therefore report for
 * themselves, which covers both of them the same way.
 *
 * Redis rather than a table: this is a liveness ping written every few seconds
 * and read on every probe, it is worthless the moment it is stale, and the key
 * expiring on its own is exactly the behaviour wanted.
 */
/*
 * Deliberately not a `readonly class`, unlike most services here: it carries the
 * write throttle below, which is mutable by nature. The container shares one
 * instance per process, so instance state is exactly the right scope — the
 * throttle should span a worker's whole run and nothing wider.
 */
class WorkerHeartbeat
{
    /**
     * How long a recorded beat is kept.
     *
     * Only needs to outlive {@see HealthChecker::WORKER_HEARTBEAT_MAX_AGE_SECONDS}
     * — the staleness decision belongs to the health check, not here, so the key
     * is kept comfortably longer and the age is compared explicitly. An absent
     * key then means "not seen in an hour", which reads the same as dead.
     */
    private const int TTL_SECONDS = 3600;

    /**
     * Minimum gap between two writes for one transport.
     *
     * An idle worker loops roughly once a second, so writing on every loop would
     * mean a Redis round trip per second per worker forever, to record something
     * that is only ever read against a multi-minute threshold.
     */
    private const int WRITE_INTERVAL_SECONDS = 15;

    private const string KEY_PREFIX = 'aihm:worker:heartbeat:';

    /** @var array<string, int> transport => unix time we last wrote */
    private array $lastWrite = [];

    public function __construct(
        #[Autowire(service: 'app.redis')]
        private readonly Redis $redis,
    ) {
    }

    /**
     * Notes that the worker consuming $transport is alive, at most once every
     * {@see WRITE_INTERVAL_SECONDS}.
     *
     * Never throws. This runs inside the worker's own loop, and a Redis blip must
     * not kill a process whose job is to drain the queue — losing a beat costs a
     * misleading `degraded` reading, killing the worker costs the queue.
     */
    public function record(string $transport, ?int $now = null): void
    {
        $now ??= time();

        $last = $this->lastWrite[$transport] ?? null;
        if (null !== $last && ($now - $last) < self::WRITE_INTERVAL_SECONDS) {
            return;
        }

        try {
            $this->redis->setex(self::KEY_PREFIX.$transport, self::TTL_SECONDS, (string) $now);
            $this->lastWrite[$transport] = $now;
        } catch (Throwable) {
            // Deliberately swallowed — see the docblock. The throttle is not
            // updated, so the next loop retries rather than waiting out the
            // interval on a write that never landed.
        }
    }

    /**
     * Unix time of the last recorded beat, or null when there is none — never
     * seen, or seen so long ago the key expired.
     *
     * Returns null on a Redis failure too. The caller cannot tell "no worker"
     * from "cannot ask", and deliberately does not need to: both mean the probe
     * has no evidence that anything is consuming, and Redis has its own
     * component in the same response to say which it was.
     */
    public function lastSeen(string $transport): ?int
    {
        try {
            $value = $this->redis->get(self::KEY_PREFIX.$transport);
        } catch (Throwable) {
            return null;
        }

        if (!is_string($value) || '' === $value || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
