<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the two log-volume rules that live in Symfony config rather than in a
 * Compose file, and that `ProductionRuntimeConfigTest` therefore cannot see.
 */
final class MonologConfigurationTest extends TestCase
{
    /**
     * A plain `stream` handler never trims its own file; this one grew to
     * 640M with nothing to notice. `rotating_file` with `max_files` set
     * bounds it without a host-level cron or logrotate entry.
     */
    public function testDevelopmentLogIsRotatedRatherThanAPlainStream(): void
    {
        $config = $this->parseConfig();
        $main = $config['when@dev']['monolog']['handlers']['main'] ?? [];

        self::assertSame('rotating_file', $main['type'] ?? null, 'The dev main handler is no longer rotating_file; app/var/log/dev.log will grow unbounded again.');
        self::assertGreaterThan(0, $main['max_files'] ?? 0, 'rotating_file with max_files: 0 keeps every rotated file forever, which is no limit at all.');
    }

    /**
     * The `deprecation` channel was the one handler in `when@prod` never
     * wrapped in `fingers_crossed` — it wrote every PHP deprecation notice
     * straight to stderr unconditionally, measured at ~30 lines per request
     * for a single vendor-level deprecation with nothing actionable behind
     * it in production.
     */
    public function testProductionDoesNotStreamEveryDeprecationNoticeToStderr(): void
    {
        $config = $this->parseConfig();
        $deprecation = $config['when@prod']['monolog']['handlers']['deprecation'] ?? [];

        // YAML's bare `null` parses to PHP null — MonologBundle's own config
        // resolution is what turns that into the string 'null' selecting the
        // NullHandler (confirmed via `bin/console debug:config monolog`);
        // this test reads the source file, not the resolved container.
        self::assertArrayHasKey('type', $deprecation);
        self::assertNull($deprecation['type'], 'Production deprecation logging is no longer silenced; every notice will flood stderr again.');
    }

    /**
     * Deprecations stay loud exactly where a developer is looking for them —
     * this is a production-only change.
     */
    public function testDevAndTestStillLogDeprecationsAtFullVolume(): void
    {
        $config = $this->parseConfig();

        foreach (['when@dev', 'when@test'] as $environment) {
            $deprecation = $config[$environment]['monolog']['handlers']['deprecation'] ?? null;

            self::assertNull($deprecation, sprintf('%s should not define its own deprecation handler override — deprecations flow through "main" there.', $environment));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseConfig(): array
    {
        $path = \dirname(__DIR__, 3).'/config/packages/monolog.yaml';
        self::assertFileExists($path);

        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($path);

        return $parsed;
    }
}
