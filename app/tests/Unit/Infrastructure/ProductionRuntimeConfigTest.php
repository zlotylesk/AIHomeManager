<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use App\EventListener\SecurityHeadersListener;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins how the application is RUN, which no other gate looks at.
 *
 * Every other suite exercises the code; CI runs it in `test` on a GitHub
 * runner, never inside the image the app actually ships in. That is why a
 * production stack could report `dev` with debug on and stay green — the same
 * blind spot that let the nightly backup produce empty files for a month.
 *
 * These assertions are static reads of the deployment files rather than a live
 * container, because CI has no Docker daemon to ask. What they defend is the
 * small set of edits that would silently undo the production configuration.
 */
final class ProductionRuntimeConfigTest extends TestCase
{
    /**
     * The services that run application code and therefore must run it in prod.
     */
    private const array APP_SERVICES = ['php', 'messenger_worker', 'scheduler_worker'];

    /**
     * The services that hold or carry data and answer to whoever reaches them.
     *
     * Development publishes all four to the host — that is what the MCP servers
     * and every host-side client talk to — and production publishes none.
     */
    private const array INFRASTRUCTURE_SERVICES = ['mysql', 'redis', 'rabbitmq', 'search'];

    /**
     * The services that run the disk probe, and therefore have to be able to
     * see what it measures: `php` answers `/api/health`, and `scheduler_worker`
     * asks the same checker in-process on every monitoring sweep.
     *
     * `messenger_worker` is deliberately absent — it runs neither.
     */
    private const array DISK_PROBE_SERVICES = ['php', 'scheduler_worker'];

    /**
     * Every service the base file runs outside the `monitoring` profile.
     * `messenger_worker`, `scheduler_worker` and `node` already had a restart
     * policy before this ticket; the rest gained one here. Listed together
     * because the AC is "every production service", not "every service this
     * ticket happened to touch" — a regression on any one of them, old or
     * new, has to fail here.
     */
    private const array CORE_SERVICES = [
        'php', 'nginx', 'mysql', 'redis', 'rabbitmq', 'search',
        'messenger_worker', 'scheduler_worker', 'node',
    ];

    /**
     * `CORE_SERVICES` plus `certbot`, which only the prod overlay defines —
     * the full set that needs a bounded `logging:` driver, since the default
     * `json-file` has no limit at all and the log lives on the same disk as
     * the database.
     */
    private const array LOGGED_CORE_SERVICES = [
        ...self::CORE_SERVICES,
        'certbot',
    ];

    /**
     * The `monitoring` profile's own three services. They carry their memory
     * and logging limits in the base file rather than the prod overlay,
     * because it is their only definition regardless of environment — and
     * independently of the core stack's, the same reasoning both times.
     */
    private const array MONITORING_SERVICES = ['mongodb', 'opensearch', 'graylog'];

    /**
     * The deployment files sit beside app/, not inside it.
     *
     * A containerised run only has app/ — it is mounted as /var/www/html and the
     * repository root is not in the container at all — so these files are
     * genuinely unreachable there. CI checks out the whole repository and runs
     * the suite from app/, which is where these assertions actually bite; the
     * alternative was mounting the repository root into the dev container for
     * the sake of one test.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (!is_file($this->repositoryRoot().'/docker-compose.prod.yml')) {
            self::markTestSkipped(
                'Deployment files are outside the mounted app/ directory; enforced in CI, '
                .'which checks out the whole repository.',
            );
        }
    }

    public function testProductionOverlayReplacesVolumesRatherThanAppendingToThem(): void
    {
        $prod = $this->parseYaml('docker-compose.prod.yml');

        foreach ([...self::APP_SERVICES, 'nginx'] as $service) {
            $volumes = $prod['services'][$service]['volumes'] ?? null;

            self::assertInstanceOf(
                TaggedValue::class,
                $volumes,
                sprintf(
                    'Service "%s" must declare its production volumes with a YAML tag. '
                    .'Compose MERGES untagged sequences, so without one the base file\'s '
                    .'./app:/var/www/html survives and the host working copy shadows every '
                    .'file baked into the image.',
                    $service,
                ),
            );
            self::assertSame('override', $volumes->getTag());
        }
    }

    public function testNoProductionServiceMountsSourceOverTheImage(): void
    {
        $prod = $this->parseYaml('docker-compose.prod.yml');

        foreach ([...self::APP_SERVICES, 'nginx'] as $service) {
            $volumes = $prod['services'][$service]['volumes'];
            self::assertInstanceOf(TaggedValue::class, $volumes);

            /** @var list<string> $mounts */
            $mounts = $volumes->getValue();

            foreach ($mounts as $mount) {
                self::assertStringEndsNotWith(
                    ':/var/www/html',
                    $mount,
                    sprintf('Service "%s" mounts source over the application directory.', $service),
                );
            }
        }
    }

    /**
     * The disk probe measures the filesystem holding the database data and the
     * one holding the backups, and it can only measure a path this container
     * actually has. The database's data lives in a named volume mounted into
     * the mysql service, so without a mount of its own the probe is back to
     * measuring the PHP image layer — or, now, reporting that it cannot
     * measure anything at all.
     *
     * Production is the half worth pinning: `volumes: !override` REPLACES the
     * base list, so a mount added in development and not repeated here exists
     * everywhere except the environment the measurement is for.
     */
    public function testTheServicesThatRunTheDiskProbeMountWhatItMeasures(): void
    {
        $dataDir = $this->trackedEnvironmentValue('DATABASE_DATA_DIR');
        $expected = [sprintf('mysql_data:%s:ro', $dataDir), './backups:/backups'];

        $dev = $this->parseYaml('docker-compose.yml');
        $prod = $this->parseYaml('docker-compose.prod.yml');

        foreach (self::DISK_PROBE_SERVICES as $service) {
            $prodVolumes = $prod['services'][$service]['volumes'];
            self::assertInstanceOf(TaggedValue::class, $prodVolumes);

            /** @var list<string> $prodMounts */
            $prodMounts = $prodVolumes->getValue();
            /** @var list<string> $devMounts */
            $devMounts = $dev['services'][$service]['volumes'] ?? [];

            foreach ($expected as $mount) {
                self::assertContains($mount, $devMounts, sprintf('Development service "%s" cannot see %s, so the disk probe measures nothing there.', $service, $mount));
                self::assertContains($mount, $prodMounts, sprintf('Production service "%s" cannot see %s; !override dropped the mount the disk probe needs.', $service, $mount));
            }
        }
    }

    /**
     * Read-only, because nothing in the application has any business writing to
     * the database's own files — the mount exists so a `statvfs` can reach the
     * right device, and read-only is what keeps "can see" from becoming "can
     * corrupt".
     */
    public function testTheDatabaseVolumeIsNeverMountedWritableIntoTheApplication(): void
    {
        foreach (['docker-compose.yml', 'docker-compose.prod.yml'] as $file) {
            $services = $this->parseYaml($file)['services'] ?? [];
            self::assertIsArray($services);

            foreach ($services as $name => $definition) {
                if ('mysql' === $name || !is_array($definition)) {
                    continue;
                }

                $volumes = $definition['volumes'] ?? [];
                $mounts = $volumes instanceof TaggedValue ? $volumes->getValue() : $volumes;
                self::assertIsArray($mounts);

                foreach ($mounts as $mount) {
                    if (!is_string($mount) || !str_starts_with($mount, 'mysql_data:')) {
                        continue;
                    }

                    self::assertStringEndsWith(':ro', $mount, sprintf('%s: service "%s" mounts the database volume writable.', $file, $name));
                }
            }
        }
    }

    public function testApplicationServicesRunInProdWithDebugOff(): void
    {
        $prod = $this->parseYaml('docker-compose.prod.yml');

        foreach (self::APP_SERVICES as $service) {
            $environment = $prod['services'][$service]['environment'] ?? [];

            self::assertSame('prod', $environment['APP_ENV'] ?? null, $service);
            self::assertSame(0, $environment['APP_DEBUG'] ?? null, $service);
            self::assertSame('prod', $prod['services'][$service]['build']['target'] ?? null, $service);
        }
    }

    public function testApplicationServicesReceiveTheHostSecretsFile(): void
    {
        $prod = $this->parseYaml('docker-compose.prod.yml');

        foreach (self::APP_SERVICES as $service) {
            self::assertContains(
                'app/.env.local',
                $prod['services'][$service]['env_file'] ?? [],
                sprintf(
                    'Service "%s" gets no secrets. The image excludes app/.env.local on purpose, '
                    .'so without an env_file the container falls back to the empty placeholders in '
                    .'the tracked app/.env — an instance with an empty API_KEY and an empty '
                    .'FRONTEND_PASSWORD_HASH that reports itself healthy.',
                    $service,
                ),
            );
        }
    }

    /**
     * `restart` is a scalar field: the prod overlay does not redeclare it for
     * `messenger_worker`/`scheduler_worker`/`node` and still inherits it, so
     * asserting against the base file alone is enough to pin what the merged
     * production configuration actually runs with. `certbot` only exists in
     * the overlay and is checked separately below.
     */
    public function testEveryProductionServiceRecoversOnItsOwnAfterAHostReboot(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');

        foreach ([...self::CORE_SERVICES, ...self::MONITORING_SERVICES] as $service) {
            self::assertSame(
                'unless-stopped',
                $dev['services'][$service]['restart'] ?? null,
                sprintf('Service "%s" still has no restart policy, so a host reboot leaves it down.', $service),
            );
        }
    }

    public function testCertbotHasARestartPolicy(): void
    {
        $prod = $this->parseYaml('docker-compose.prod.yml');

        self::assertSame('unless-stopped', $prod['services']['certbot']['restart'] ?? null);
    }

    /**
     * Prod-only, and deliberately not in the base file: `php` and
     * `scheduler_worker` are also where `docker exec` runs heavier local
     * tooling — `make phpstan` alone asks for `--memory-limit=1G` — and a
     * ceiling sized for the running application would starve that on a
     * development machine.
     */
    public function testEveryCoreProductionServiceHasAMemoryLimit(): void
    {
        $prod = $this->parseYaml('docker-compose.prod.yml');

        foreach ([...self::APP_SERVICES, 'nginx', ...self::INFRASTRUCTURE_SERVICES, 'certbot'] as $service) {
            $limit = $prod['services'][$service]['mem_limit'] ?? null;

            self::assertIsString($limit, sprintf('Service "%s" has no memory limit in production.', $service));
            self::assertMatchesRegularExpression(
                '/^\d+[kmg]$/i',
                $limit,
                sprintf('Service "%s" has a memory limit in an unexpected format: "%s".', $service, $limit),
            );
        }
    }

    /**
     * Separate from the assertion above: without this, sizing `mysql` and the
     * monitoring stack independently is only a comment, not something a
     * change to either could actually break.
     */
    public function testTheMonitoringProfileHasItsOwnMemoryLimitsSeparateFromTheCoreStack(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');

        foreach (self::MONITORING_SERVICES as $service) {
            $limit = $dev['services'][$service]['mem_limit'] ?? null;

            self::assertIsString($limit, sprintf('Monitoring service "%s" has no memory limit.', $service));
            self::assertMatchesRegularExpression('/^\d+[kmg]$/i', $limit, $service);
        }
    }

    /**
     * The default `json-file` driver has no limit at all, and the log lives
     * on the same disk as the database — an unbounded log is exactly the
     * failure mode `disk_database` going `down` at 95% exists to catch, one
     * layer further up. `certbot` is checked against the prod overlay, the
     * only file that defines it; every other service is checked against the
     * base file, which the overlay inherits unmodified.
     */
    public function testEveryCoreServiceHasABoundedLoggingDriver(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');
        $prod = $this->parseYaml('docker-compose.prod.yml');

        foreach (self::LOGGED_CORE_SERVICES as $service) {
            $definition = 'certbot' === $service ? $prod['services'][$service] : $dev['services'][$service];

            self::assertLoggingIsBounded($definition, $service);
        }
    }

    public function testTheMonitoringProfileHasItsOwnLoggingLimitsSeparateFromTheCoreStack(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');

        foreach (self::MONITORING_SERVICES as $service) {
            self::assertLoggingIsBounded($dev['services'][$service], $service);
        }
    }

    /**
     * A guard against a typo rather than a tuned budget: a limit entered as
     * "10240m" instead of "1024m" would pass the two tests above on its own
     * and only show up here, where the total no longer fits any host anyone
     * would actually run this on.
     */
    public function testTheMemoryLimitsFitAConservativeHostBudget(): void
    {
        $prod = $this->parseYaml('docker-compose.prod.yml');
        $dev = $this->parseYaml('docker-compose.yml');

        $coreServices = [...self::APP_SERVICES, 'nginx', ...self::INFRASTRUCTURE_SERVICES, 'certbot'];
        $coreBytes = array_sum(array_map(
            fn (string $service): int => self::bytesFromMemoryLimit((string) $prod['services'][$service]['mem_limit']),
            $coreServices,
        ));
        $monitoringBytes = array_sum(array_map(
            fn (string $service): int => self::bytesFromMemoryLimit((string) $dev['services'][$service]['mem_limit']),
            self::MONITORING_SERVICES,
        ));

        // ~5.2 GiB lean, ~7.7 GiB with monitoring — see docs/operations.md.
        // The ceilings below leave generous slack for a deliberate re-sizing
        // while still catching an order-of-magnitude typo.
        self::assertLessThan(8 * 1024 ** 3, $coreBytes, 'The lean production stack no longer fits an 8 GB host with room for the OS.');
        self::assertLessThan(12 * 1024 ** 3, $coreBytes + $monitoringBytes, 'The stack with monitoring enabled no longer fits a 12 GB host with room for the OS.');
    }

    /**
     * `php` has no HTTP server of its own, only php-fpm's socket — so the
     * probe has to speak FastCGI, the same protocol nginx does, rather than
     * ask an HTTP route that would need the application fully booted to
     * answer.
     */
    public function testPhpHasAFastCgiHealthcheck(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');
        $test = implode(' ', (array) ($dev['services']['php']['healthcheck']['test'] ?? []));

        self::assertStringContainsString('cgi-fcgi', $test, 'php no longer probes php-fpm directly.');
        self::assertStringContainsString('pong', $test, 'php no longer checks for the ping responder\'s expected reply.');
    }

    /**
     * Without this, a fresh boot has nginx proxying to a php-fpm that has not
     * finished starting, and every request in that window answers 502 —
     * exactly the manual-intervention moment the AC rules out.
     */
    public function testNginxWaitsForPhpToBeHealthyBeforeStarting(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');

        self::assertSame(
            'service_healthy',
            $dev['services']['nginx']['depends_on']['php']['condition'] ?? null,
            'nginx no longer waits for php to be healthy before it starts proxying to it.',
        );
    }

    /**
     * `php` handles requests that reach mysql, redis and rabbitmq directly
     * (a cache read, a lock, an async dispatch), so a request served during
     * the boot window would fail against a dependency that has not started
     * yet.
     */
    public function testPhpWaitsForItsDependenciesToBeHealthyBeforeStarting(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');
        $dependsOn = $dev['services']['php']['depends_on'] ?? [];

        foreach (['mysql', 'redis', 'rabbitmq'] as $dependency) {
            self::assertSame(
                'service_healthy',
                $dependsOn[$dependency]['condition'] ?? null,
                sprintf('php no longer waits for "%s" to be healthy before it starts.', $dependency),
            );
        }
    }

    /**
     * A guard against a typo rather than a tuned budget, the same reasoning
     * as the memory-limit sum test: a `max-size` entered as "200m" instead of
     * "20m" would pass the assertion above on its own.
     */
    public function testTheLoggingLimitsFitAConservativeTotalBudget(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');
        $prod = $this->parseYaml('docker-compose.prod.yml');

        $coreBytes = 0;
        foreach (self::LOGGED_CORE_SERVICES as $service) {
            $definition = 'certbot' === $service ? $prod['services'][$service] : $dev['services'][$service];
            $coreBytes += self::loggingCapacityInBytes($definition);
        }

        $monitoringBytes = 0;
        foreach (self::MONITORING_SERVICES as $service) {
            $monitoringBytes += self::loggingCapacityInBytes($dev['services'][$service]);
        }

        // ~500M lean, ~650M with monitoring — see docs/operations.md.
        self::assertLessThan(1024 ** 3, $coreBytes, 'The lean stack\'s logging budget no longer fits comfortably under 1 GiB.');
        self::assertLessThan(1536 * 1024 ** 2, $coreBytes + $monitoringBytes, 'The stack with monitoring enabled no longer fits comfortably under 1.5 GiB of logs.');
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function assertLoggingIsBounded(array $definition, string $service): void
    {
        self::assertSame('json-file', $definition['logging']['driver'] ?? null, sprintf('Service "%s" no longer uses the json-file logging driver.', $service));

        $options = $definition['logging']['options'] ?? [];
        self::assertMatchesRegularExpression('/^\d+[km]$/i', (string) ($options['max-size'] ?? ''), sprintf('Service "%s" has no bounded max-size.', $service));
        self::assertMatchesRegularExpression('/^\d+$/', (string) ($options['max-file'] ?? ''), sprintf('Service "%s" has no bounded max-file.', $service));
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function loggingCapacityInBytes(array $definition): int
    {
        $options = $definition['logging']['options'] ?? [];

        if (1 !== preg_match('/^(\d+)([km])$/i', (string) ($options['max-size'] ?? ''), $matches)) {
            throw new InvalidArgumentException('Not a recognised max-size.');
        }

        $multiplier = 'k' === strtolower($matches[2]) ? 1024 : 1024 ** 2;

        return (int) $matches[1] * $multiplier * (int) ($options['max-file'] ?? 0);
    }

    private static function bytesFromMemoryLimit(string $limit): int
    {
        if (1 !== preg_match('/^(\d+)([kmg])$/i', $limit, $matches)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a recognised memory limit.', $limit));
        }

        $multiplier = match (strtolower($matches[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            default => 1024 ** 3,
        };

        return (int) $matches[1] * $multiplier;
    }

    public function testDevelopmentStillMountsSourceForEveryApplicationService(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');

        foreach (self::APP_SERVICES as $service) {
            self::assertContains(
                './app:/var/www/html',
                $dev['services'][$service]['volumes'] ?? [],
                sprintf('Development lost its bind mount for "%s"; an edit would need a rebuild.', $service),
            );
            self::assertSame('dev', $dev['services'][$service]['build']['target'] ?? null, $service);
        }
    }

    /**
     * Nothing outside the host may reach the database, the cache, the broker or
     * the search engine.
     *
     * The assertion is on the OVERRIDE TAG as much as on the value: Compose
     * appends untagged sequences, so an overlay that listed loopback bindings
     * without the tag would end up publishing both those and the base file's
     * 0.0.0.0 mappings — wide open, while reading as closed.
     */
    public function testProductionPublishesNoInfrastructurePortToTheNetwork(): void
    {
        $prod = $this->parseYaml('docker-compose.prod.yml');

        foreach (self::INFRASTRUCTURE_SERVICES as $service) {
            $ports = $prod['services'][$service]['ports'] ?? null;

            self::assertInstanceOf(
                TaggedValue::class,
                $ports,
                sprintf(
                    'Service "%s" does not override its published ports, so it inherits the '
                    .'development mapping and answers on every interface of the host.',
                    $service,
                ),
            );
            self::assertSame('override', $ports->getTag(), $service);

            /** @var list<string> $published */
            $published = $ports->getValue();

            foreach ($published as $mapping) {
                self::assertStringStartsWith(
                    '127.0.0.1:',
                    $mapping,
                    sprintf('Service "%s" publishes %s beyond the loopback interface.', $service, $mapping),
                );
            }
        }
    }

    /**
     * The counterweight to the test above, and the reason it is a separate one:
     * the cheapest way to make that assertion pass everywhere would be to stop
     * publishing these ports at all, which would take the host tooling — the
     * MySQL and Redis MCP servers, a GUI client, the broker's management UI —
     * down with it for no gain on a development box.
     */
    public function testDevelopmentStillPublishesInfrastructurePortsForHostTooling(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');

        foreach (self::INFRASTRUCTURE_SERVICES as $service) {
            self::assertNotEmpty(
                $dev['services'][$service]['ports'] ?? [],
                sprintf('Development no longer publishes "%s"; host tooling cannot reach it.', $service),
            );
        }
    }

    /**
     * The broker's built-in account is reachable from anywhere the port is,
     * because the image ships `loopback_users.guest = false` in its own
     * conf.d — compiled in, not derived from the environment, so choosing a
     * different default account does not switch it off.
     *
     * Two independent things therefore have to hold, and each covers a case the
     * other does not: a fresh volume must never create `guest` (RabbitMQ only
     * creates the default user when it initialises an empty database), and a
     * volume that already has one must not expose it off the loopback
     * interface.
     */
    public function testTheBrokerDoesNotRunAsTheBuiltInGuestAccount(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');
        $environment = $dev['services']['rabbitmq']['environment'] ?? [];

        foreach (['RABBITMQ_DEFAULT_USER', 'RABBITMQ_DEFAULT_PASS'] as $variable) {
            $value = $environment[$variable] ?? null;

            self::assertIsString($value, $variable);
            self::assertStringStartsWith(
                '${',
                $value,
                sprintf('%s is a literal again; the credential is back in a tracked file.', $variable),
            );
        }

        self::assertStringNotContainsString(
            'guest',
            (string) ($environment['RABBITMQ_DEFAULT_USER'] ?? ''),
            'The broker boots as the built-in guest account, which the image opens to the whole network.',
        );
    }

    public function testTheGuestAccountIsConfinedToTheLoopbackInterface(): void
    {
        self::assertMatchesRegularExpression(
            '/^loopback_users\.guest\s*=\s*true$/m',
            $this->read('docker/rabbitmq/20-aihm.conf'),
            'The broker configuration no longer confines the built-in account to loopback.',
        );

        // Mounted, or the file is a note to nobody. It has to sort after the
        // image's own 10-defaults.conf — RabbitMQ reads conf.d in lexical order
        // and the later file wins.
        $mounts = $this->parseYaml('docker-compose.yml')['services']['rabbitmq']['volumes'] ?? [];

        self::assertContains(
            './docker/rabbitmq/20-aihm.conf:/etc/rabbitmq/conf.d/20-aihm.conf:ro',
            $mounts,
            'The broker no longer reads the configuration that confines the guest account.',
        );
    }

    /**
     * Redis had no `requirepass` at all, so anything that could open the port
     * had full read and write access to the caches, the locks and the worker
     * heartbeats without so much as a password to guess.
     */
    public function testRedisRequiresAPasswordAndEveryClientSendsOne(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');

        self::assertStringContainsString(
            '--requirepass',
            implode(' ', (array) ($dev['services']['redis']['command'] ?? [])),
            'Redis is started without a password again.',
        );

        // LOCK_DSN as well as REDIS_URL, and it is the one worth pinning: it is
        // a separate variable read by config/packages/lock.yaml rather than
        // anything derived from REDIS_URL, so it is the one that gets forgotten
        // — and the symptom is every lock acquisition failing while the caches
        // carry on working.
        foreach (self::APP_SERVICES as $service) {
            foreach (['REDIS_URL', 'LOCK_DSN'] as $variable) {
                $dsn = $dev['services'][$service]['environment'][$variable] ?? null;

                self::assertIsString($dsn, sprintf('%s does not set %s.', $service, $variable));
                self::assertStringStartsWith(
                    'redis://:${',
                    $dsn,
                    sprintf('%s reaches Redis without credentials via %s.', $service, $variable),
                );
            }
        }
    }

    /**
     * The server default is `noeviction` — correct for a database, wrong for
     * a cache, because once full it starts rejecting writes with an error
     * instead of quietly forgetting the oldest entry. `allkeys-lru` rather
     * than `volatile-lru`: every key this instance holds already carries a
     * TTL (see docs/operations.md for the per-key-group review), so the two
     * evict identically today, but `volatile-lru` would degrade back to
     * `noeviction`'s write errors the moment the keyspace ever filled with a
     * non-expiring key, which `allkeys-lru` can never do.
     */
    public function testRedisHasAMemoryLimitAndAnEvictionPolicySuitedToACache(): void
    {
        $dev = $this->parseYaml('docker-compose.yml');
        $command = implode(' ', (array) ($dev['services']['redis']['command'] ?? []));

        self::assertStringContainsString(
            '--maxmemory 192mb',
            $command,
            'Redis is started without a memory ceiling again — writes can fill the container.',
        );
        self::assertStringContainsString(
            '--maxmemory-policy allkeys-lru',
            $command,
            'Redis eviction policy regressed away from allkeys-lru — a full cache would start rejecting writes.',
        );
    }

    /**
     * The tracked reference file is what someone copies into .env.local when
     * setting an instance up, so a default credential surviving here outlives
     * every correction made in the compose files.
     */
    public function testTheTrackedReferenceDsnsCarryNoDefaultCredentials(): void
    {
        $env = $this->read('app/.env');

        self::assertStringNotContainsString('amqp://guest:guest@', $env, 'The reference broker DSN is back on the built-in account.');
        self::assertMatchesRegularExpression('/^REDIS_URL=redis:\/\/:\S+@/m', $env, 'The reference Redis DSN carries no password.');
        self::assertMatchesRegularExpression('/^LOCK_DSN=redis:\/\/:\S+@/m', $env, 'The reference lock DSN carries no password.');
    }

    /**
     * Graylog shipped its administrator password and its session pepper as
     * literals in the compose file — and the password was the image's own
     * default, so the tracked value and the guessable value were the same one.
     */
    public function testGraylogTakesItsCredentialsFromTheEnvironmentWithoutADefault(): void
    {
        $environment = $this->parseYaml('docker-compose.yml')['services']['graylog']['environment'] ?? [];

        foreach (['GRAYLOG_PASSWORD_SECRET', 'GRAYLOG_ROOT_PASSWORD_SHA2'] as $variable) {
            $value = $environment[$variable] ?? null;

            self::assertIsString($value, $variable);
            self::assertStringStartsWith(
                '${',
                $value,
                sprintf('%s is a literal again; the credential is back in a tracked file.', $variable),
            );

            // `:?` rather than `:-`. A defaulted variable reads as configurable
            // while a production stack that sets nothing still comes up on
            // whatever the default is, and the way that gets discovered is the
            // login page accepting it.
            self::assertStringContainsString(
                ':?',
                $value,
                sprintf('%s has a default, so an unset production value fails silently instead of loudly.', $variable),
            );
        }

        // sha256("admin"), the image's default administrator password. Named
        // explicitly because the assertions above pass the moment the literal is
        // moved rather than replaced — into an env_file, a second compose file,
        // the default half of a `:-` expression.
        self::assertStringNotContainsString(
            '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918',
            $this->read('docker-compose.yml'),
            'The default Graylog administrator password is back in the compose file.',
        );
    }

    /**
     * Every `${VAR:?}` in the compose files is a variable with no default, so a
     * missing one is a stack that refuses to start. That is the right behaviour
     * for a production secret and the wrong one for `git clone && make up`,
     * which is why the tracked `.env` has to answer all of them.
     *
     * Compose reads only `.env` from the project directory — never `.env.local`
     * — so this file is the whole of the development baseline.
     */
    public function testEveryGuardedComposeVariableHasADevelopmentValueInTheTrackedEnvironment(): void
    {
        preg_match_all('/\$\{([A-Z0-9_]+):\?/', $this->read('docker-compose.yml'), $matches);

        $guarded = array_unique($matches[1]);
        self::assertNotEmpty($guarded, 'No guarded variables found — the pattern this test reads has changed.');

        $tracked = $this->read('.env');

        foreach ($guarded as $variable) {
            self::assertMatchesRegularExpression(
                '/^'.preg_quote($variable, '/').'=.+$/m',
                $tracked,
                sprintf(
                    'docker-compose.yml requires %s and the tracked .env does not set it, '
                    .'so a fresh clone cannot start the stack at all.',
                    $variable,
                ),
            );
        }
    }

    /**
     * The tracked `.env` is the one file a deployment is tempted to edit — it is
     * the only one Compose reads, and it already has the right variable names in
     * it. Saying so in the file is the cheapest place to stop that, and the only
     * one an operator is guaranteed to be looking at when the temptation
     * arrives.
     */
    public function testTheTrackedComposeEnvironmentWarnsAgainstProductionSecrets(): void
    {
        self::assertStringContainsString(
            'DO NOT PUT A PRODUCTION SECRET IN HERE',
            $this->read('.env'),
            'The tracked .env no longer says it is development-only, so its next reader has no reason to think it is.',
        );
    }

    public function testProductionOpcacheDoesNotStatEveryFileOnEveryRequest(): void
    {
        $ini = parse_ini_string($this->read('docker/php/opcache-prod.ini'));

        self::assertIsArray($ini);
        self::assertSame('0', $ini['opcache.validate_timestamps'] ?? null);
        self::assertSame('1', $ini['opcache.enable'] ?? null);

        // src+vendor+config carry ~50k PHP files. The 10000-slot default sits
        // below the working set, so the cache evicts and recompiles instead of
        // settling — OPcache "on" and doing nothing.
        self::assertGreaterThan(
            10000,
            (int) ($ini['opcache.max_accelerated_files'] ?? 0),
            'The accelerated-file limit is back at or below the PHP default.',
        );
    }

    public function testProductionImageInstallsWithoutDevelopmentDependenciesAndWarmsTheCache(): void
    {
        $dockerfile = $this->read('docker/php/Dockerfile');
        $prodStage = substr($dockerfile, (int) strpos($dockerfile, 'FROM base AS prod'));

        self::assertStringContainsString('--no-dev', $prodStage);
        self::assertStringContainsString('cache:warmup', $prodStage);
        self::assertStringContainsString('APP_ENV=prod', $prodStage);
    }

    public function testBuildContextExcludesLocalEnvironmentFiles(): void
    {
        $ignored = array_map(trim(...), explode("\n", $this->read('.dockerignore')));

        // app/.env.local holds the real credentials on the owner's machine, and
        // the prod stage copies app/ wholesale. A layer that captured it would
        // keep it even after a later delete.
        self::assertContains('**/.env.local', $ignored);
        self::assertContains('**/.env.*.local', $ignored);
    }

    public function testPlainHttpRedirectsToHttpsWithoutSwallowingTheAcmeChallenge(): void
    {
        $conf = $this->read('docker/nginx/default.prod.conf');

        self::assertMatchesRegularExpression(
            '/return\s+301\s+https:\/\/\$host\$request_uri;/',
            $conf,
            'Plain HTTP no longer redirects to HTTPS, or no longer keeps the path and query.',
        );

        // `^~` is what makes this beat the `/` prefix that carries the
        // redirect. Downgraded to a plain prefix match the challenge would be
        // redirected to HTTPS, and the one moment it has to work is when the
        // certificate has expired and HTTPS is exactly what does not.
        self::assertStringContainsString(
            'location ^~ /.well-known/acme-challenge/',
            $conf,
            'The ACME challenge is no longer exempt from the redirect; renewal cannot recover an expired certificate.',
        );
    }

    /**
     * nginx does not ADD a location-level `add_header` to the inherited ones —
     * it replaces them. A location that sets any header of its own therefore
     * silently loses every security header unless it re-declares the set, and
     * /sw.js sets two of its own.
     */
    public function testEveryLocationThatSetsAHeaderReDeclaresTheSecurityHeaders(): void
    {
        foreach (['docker/nginx/snippets/app.conf', 'docker/nginx/default.conf', 'docker/nginx/default.prod.conf'] as $file) {
            foreach ($this->locationBlocks($this->read($file)) as $location => $body) {
                if (!str_contains($body, 'add_header')) {
                    continue;
                }

                self::assertStringContainsString(
                    'include /etc/nginx/snippets/security-headers.conf;',
                    $body,
                    sprintf(
                        '%s: "%s" sets headers of its own, which discards the inherited ones. '
                        .'It must include the shared set or it answers with none of them.',
                        $file,
                        $location,
                    ),
                );
            }
        }
    }

    public function testTheTwoLayersAnnounceTheSameHstsPolicy(): void
    {
        $map = $this->read('docker/nginx/snippets/hsts-map.conf');

        self::assertStringContainsString(
            '"'.SecurityHeadersListener::STRICT_TRANSPORT_SECURITY.'"',
            $map,
            'nginx and SecurityHeadersListener now advertise different HSTS policies; '
            .'which one applies would depend on the last response the browser happened to see.',
        );

        // Keyed on the scheme, not a constant: the header is meaningless over
        // plain HTTP and a browser must ignore it there.
        self::assertMatchesRegularExpression(
            '/map\s+\$scheme\s+\$hsts_max_age/',
            $map,
            'HSTS is no longer conditional on the scheme.',
        );
        self::assertMatchesRegularExpression(
            '/default\s+"";/',
            $map,
            'The plain-HTTP branch no longer maps to an empty value, which is what makes nginx omit the header.',
        );
    }

    /**
     * Renewal writes the challenge token to a directory; nginx serves it from
     * one. If those are not the same directory, everything looks correct and
     * healthy for sixty days and then the certificate expires.
     */
    public function testRenewalWritesTheChallengeWhereNginxServesIt(): void
    {
        $prod = $this->parseYaml('docker-compose.prod.yml');

        $nginxVolumes = $prod['services']['nginx']['volumes'];
        self::assertInstanceOf(TaggedValue::class, $nginxVolumes);

        /** @var list<string> $nginxMounts */
        $nginxMounts = $nginxVolumes->getValue();
        /** @var list<string> $certbotMounts */
        $certbotMounts = $prod['services']['certbot']['volumes'] ?? [];

        foreach (['certbot_webroot:/var/www/certbot', 'letsencrypt:/etc/letsencrypt'] as $shared) {
            self::assertContains($shared, $nginxMounts, 'nginx lost the shared mount '.$shared);
            self::assertContains($shared, $certbotMounts, 'certbot lost the shared mount '.$shared);
        }

        self::assertStringContainsString(
            'root /var/www/certbot;',
            $this->read('docker/nginx/default.prod.conf'),
            'nginx serves the ACME challenge from somewhere other than the directory certbot writes it to.',
        );
    }

    /**
     * Splits an nginx configuration into its `location` blocks.
     *
     * Brace counting rather than a regex: the bodies contain braces of their
     * own, and a non-greedy match to the first `}` would cut a block short and
     * quietly pass the assertion above for the part it did not read.
     *
     * @return array<string, string> header line => block body
     */
    private function locationBlocks(string $config): array
    {
        $blocks = [];
        $offset = 0;

        while (false !== ($start = strpos($config, 'location ', $offset))) {
            $open = strpos($config, '{', $start);

            if (false === $open) {
                break;
            }

            $depth = 0;
            $cursor = $open;
            $length = \strlen($config);

            while ($cursor < $length) {
                if ('{' === $config[$cursor]) {
                    ++$depth;
                } elseif ('}' === $config[$cursor]) {
                    --$depth;

                    if (0 === $depth) {
                        break;
                    }
                }

                ++$cursor;
            }

            $blocks[trim(substr($config, $start, $open - $start))] = substr($config, $open, $cursor - $open + 1);
            $offset = $cursor + 1;
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseYaml(string $relativePath): array
    {
        // PARSE_CUSTOM_TAGS: Compose's own !override tag is not part of the YAML
        // core schema, and the parser rejects unknown tags without it.
        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parse($this->read($relativePath), Yaml::PARSE_CUSTOM_TAGS);

        return $parsed;
    }

    /**
     * A value from the tracked `app/.env`, so a path is read from the one place
     * that defines it rather than restated here — a mount and the variable
     * naming it are only useful while they agree.
     */
    private function trackedEnvironmentValue(string $variable): string
    {
        self::assertSame(
            1,
            preg_match('/^'.preg_quote($variable, '/').'=(.+)$/m', $this->read('app/.env'), $matches),
            sprintf('app/.env no longer defines %s.', $variable),
        );

        return trim($matches[1], "\"' \r");
    }

    private function read(string $relativePath): string
    {
        $absolute = $this->repositoryRoot().'/'.$relativePath;

        self::assertFileExists($absolute);

        return (string) file_get_contents($absolute);
    }

    private function repositoryRoot(): string
    {
        // tests/Unit/Infrastructure → tests/Unit → tests → app → the root.
        return \dirname(__DIR__, 4);
    }
}
