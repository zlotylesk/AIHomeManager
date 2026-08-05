<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

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
