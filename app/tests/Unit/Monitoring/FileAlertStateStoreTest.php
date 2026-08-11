<?php

declare(strict_types=1);

namespace App\Tests\Unit\Monitoring;

use App\Monitoring\AlertSeverity;
use App\Monitoring\FileAlertStateStore;
use App\Monitoring\StoredAlert;
use App\Tests\Support\SpyLogger;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FileAlertStateStoreTest extends TestCase
{
    private string $directory;
    private string $stateFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/aihm-alert-state-'.bin2hex(random_bytes(6));
        $this->stateFile = $this->directory.'/nested/alert-state.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            unlink($this->stateFile);
        }

        foreach ([\dirname($this->stateFile), $this->directory] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testRoundTripsAlertsThroughTheFile(): void
    {
        $store = new FileAlertStateStore($this->stateFile, new NullLogger());
        $since = new DateTimeImmutable('2026-08-11 09:00:00+02:00');

        $store->save([
            'health:mysql' => new StoredAlert('health:mysql', AlertSeverity::CRITICAL, 'mysql is down', $since),
            'queue:failed' => new StoredAlert('queue:failed', AlertSeverity::WARNING, '3 in the DLQ', $since),
        ]);

        $loaded = new FileAlertStateStore($this->stateFile, new NullLogger())->load();

        self::assertSame(['health:mysql', 'queue:failed'], array_keys($loaded));
        self::assertSame(AlertSeverity::CRITICAL, $loaded['health:mysql']->severity);
        self::assertSame('mysql is down', $loaded['health:mysql']->title);
        self::assertSame($since->getTimestamp(), $loaded['health:mysql']->since->getTimestamp());
    }

    public function testCreatesTheDirectoryItWritesInto(): void
    {
        $store = new FileAlertStateStore($this->stateFile, new NullLogger());

        $store->save([]);

        self::assertFileExists($this->stateFile);
    }

    public function testAnAbsentFileMeansNothingIsFiring(): void
    {
        self::assertSame([], new FileAlertStateStore($this->stateFile, new NullLogger())->load());
    }

    public function testAnUnreadableFileIsDiscardedAndLoggedRatherThanThrown(): void
    {
        $logger = new SpyLogger();
        $this->writeRaw('{ this is not json');

        $loaded = new FileAlertStateStore($this->stateFile, $logger)->load();

        self::assertSame([], $loaded, 'A corrupt state file must not take down the monitor at the moment it is needed.');

        $logged = $logger->findByMessage('Monitoring alert state is unreadable');
        self::assertNotNull($logged);
        self::assertSame('warning', $logged['level']);
    }

    public function testAnEntryOfAnUnknownShapeIsSkippedWithoutLosingTheOthers(): void
    {
        $this->writeRaw(json_encode([
            ['key' => 'health:mysql', 'severity' => 'critical', 'title' => 'mysql is down', 'since' => '2026-08-11T09:00:00+02:00'],
            ['key' => 'health:redis', 'severity' => 'catastrophic', 'title' => 'redis is down', 'since' => '2026-08-11T09:00:00+02:00'],
            ['key' => 'queue:failed'],
            'not even an object',
        ], \JSON_THROW_ON_ERROR));

        $loaded = new FileAlertStateStore($this->stateFile, new NullLogger())->load();

        self::assertSame(['health:mysql'], array_keys($loaded));
    }

    private function writeRaw(string $contents): void
    {
        mkdir(\dirname($this->stateFile), 0o755, true);
        file_put_contents($this->stateFile, $contents);
    }
}
