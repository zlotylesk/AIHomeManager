<?php

declare(strict_types=1);

namespace App\Tests\Integration\Health;

use App\Health\HealthChecker;
use App\Health\WorkerHeartbeat;
use Redis;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HMAI-418: the `worker` component, end to end over the real HTTP endpoint and
 * the real Redis the heartbeat is stored in.
 *
 * The unit tests pin the decision against a stubbed heartbeat; what they cannot
 * show is that the value a worker writes is the value the probe reads back —
 * same key, same encoding, same clock. That round trip is the whole mechanism,
 * so it is worth exercising rather than assuming.
 */
final class WorkerHealthTest extends WebTestCase
{
    private const string KEY_PREFIX = 'aihm:worker:heartbeat:';
    private const array TRANSPORTS = ['async', 'scheduler_default'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        // The client boots the kernel, so it has to come first: the container
        // the cleanup below reaches for does not exist until it has.
        $this->client = static::createClient();
        $this->forgetAllHeartbeats();
    }

    protected function tearDown(): void
    {
        $this->forgetAllHeartbeats();

        parent::tearDown();
    }

    public function testWorkerIsDegradedWhenNothingIsConsuming(): void
    {
        $this->client->request('GET', '/api/health');

        $body = $this->decode($this->client->getResponse()->getContent());

        self::assertSame('degraded', $body['components']['worker']);
    }

    public function testADeadWorkerIsNeverReportedDown(): void
    {
        // The point of the `degraded` classification. An instance whose workers
        // are dead still serves every request it is asked to, so 503-ing it out
        // of rotation would remove the half that works without restoring the
        // half that does not — the same rule the `search` component set.
        //
        // Deliberately asserted on the component rather than on the HTTP status:
        // which OTHER components are up is a property of the environment, not of
        // this change (CI runs no RabbitMQ, so the endpoint legitimately answers
        // 503 there), and HealthEndpointTest already refuses to assert a status
        // for exactly that reason. `degraded` never producing a 503 is the
        // controller's mapping, pinned against a stubbed checker in
        // HealthControllerTest where no environment can interfere.
        $this->client->request('GET', '/api/health');

        $worker = $this->decode($this->client->getResponse()->getContent())['components']['worker'];

        self::assertContains($worker, ['up', 'degraded']);
        self::assertSame('degraded', $worker);
    }

    public function testWorkerIsUpOnceBothWorkersHaveReportedIn(): void
    {
        $heartbeat = $this->heartbeat();
        foreach (self::TRANSPORTS as $transport) {
            $heartbeat->record($transport);
        }

        $this->client->request('GET', '/api/health');
        $body = $this->decode($this->client->getResponse()->getContent());

        self::assertSame('up', $body['components']['worker']);
    }

    public function testOneLiveWorkerIsNotEnough(): void
    {
        // The failure that motivated this: the async worker was fine and the
        // scheduler was not, so the nightly backup stopped while every other
        // probe stayed green. A component that only reported "at least one
        // worker is alive" would have missed it exactly the same way.
        $this->heartbeat()->record('async');

        self::assertSame('degraded', $this->checker()->checkWorker());
    }

    public function testAStaleHeartbeatCountsAsDeadRatherThanAlive(): void
    {
        // Written by hand rather than through record(), because the point is a
        // beat that is present but old — the state a worker leaves behind when
        // it dies, and the one an existence-only check would call healthy.
        $redis = $this->redis();
        foreach (self::TRANSPORTS as $transport) {
            $redis->setex(self::KEY_PREFIX.$transport, 3600, (string) (time() - 3600));
        }

        self::assertSame('degraded', $this->checker()->checkWorker());
    }

    public function testTheProbeReadsBackExactlyWhatAWorkerWrote(): void
    {
        $heartbeat = $this->heartbeat();
        $heartbeat->record('async');

        // Same key the health check looks under, and a value it can parse as a
        // timestamp. A prefix or encoding drift between the two halves would
        // leave the probe permanently degraded with the workers running fine.
        $stored = $this->redis()->get(self::KEY_PREFIX.'async');
        self::assertIsString($stored);
        self::assertSame(time(), (int) $stored, 'The stored beat must be a unix timestamp of now.');
        self::assertSame(time(), $heartbeat->lastSeen('async'));
    }

    private function forgetAllHeartbeats(): void
    {
        $redis = $this->redis();
        foreach (self::TRANSPORTS as $transport) {
            $redis->del(self::KEY_PREFIX.$transport);
        }
    }

    private function heartbeat(): WorkerHeartbeat
    {
        /** @var WorkerHeartbeat $heartbeat */
        $heartbeat = static::getContainer()->get(WorkerHeartbeat::class);

        return $heartbeat;
    }

    private function checker(): HealthChecker
    {
        /** @var HealthChecker $checker */
        $checker = static::getContainer()->get(HealthChecker::class);

        return $checker;
    }

    private function redis(): Redis
    {
        /** @var Redis $redis */
        $redis = static::getContainer()->get('app.redis');

        return $redis;
    }

    /** @return array<string, mixed> */
    private function decode(string|false $content): array
    {
        self::assertIsString($content);
        $body = json_decode($content, true);
        self::assertIsArray($body);

        return $body;
    }
}
