<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Backup\Destination;

use App\Infrastructure\Backup\Destination\RemoteBackup;
use App\Infrastructure\Backup\Destination\RemoteRetention;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one policy deciding which off-host copies get deleted, shared by both
 * destinations. It is reached through `prune()` in each of their tests, but only
 * ever with a midnight `$today` — which is precisely the value production never
 * passes, so the boundary this class turns on had no test that could observe it.
 */
final class RemoteRetentionTest extends TestCase
{
    private const int KEEP_DAYS = 60;

    /**
     * The regression this file exists for.
     *
     * `DatabaseBackupService::pushOffsite()` passes `new DateTimeImmutable()`,
     * and the dump runs at 03:00 — while every destination test passes a bare
     * date, i.e. midnight. The copy dated exactly $keepDays ago sits on the
     * cutoff, so an un-normalised comparison resolved it by the clock: kept at
     * 00:00, deleted at 03:00. The window was therefore a day shorter in
     * production than the tests describing it, and the nightly sweep and a
     * manual `make backup-now` disagreed about the same directory.
     *
     * Retention is counted in whole days and compared against dates parsed out
     * of filenames, which are midnight by construction. The hour the sweep
     * happens to run at must not be part of the answer.
     *
     * @param string $now the moment the sweep is invoked at
     */
    #[DataProvider('timesOfDay')]
    public function testTheCopyOnTheCutoffSurvivesWhateverTimeTheSweepRuns(string $now): void
    {
        $onTheCutoff = $this->backup('2026-06-13');

        $expired = RemoteRetention::expired(
            [$onTheCutoff],
            new DateTimeImmutable($now),
            self::KEEP_DAYS,
        );

        self::assertSame([], $expired);
    }

    /** @return iterable<string, array{string}> */
    public static function timesOfDay(): iterable
    {
        yield 'midnight, as the destination tests invoke it' => ['2026-08-12 00:00:00'];
        yield 'the 03:00 scheduled dump' => ['2026-08-12 03:00:00'];
        yield 'a manual backup in the afternoon' => ['2026-08-12 14:37:00'];
        yield 'the last minute of the day' => ['2026-08-12 23:59:59'];
    }

    /**
     * The other half of the same boundary: one day older than the cutoff is out,
     * and stays out at every hour. Without this the test above would also pass
     * on a policy that simply never expires anything.
     *
     * @param string $now the moment the sweep is invoked at
     */
    #[DataProvider('timesOfDay')]
    public function testTheCopyOneDayPastTheCutoffIsExpiredWhateverTimeTheSweepRuns(string $now): void
    {
        $pastTheCutoff = $this->backup('2026-06-12');

        $expired = RemoteRetention::expired(
            [$pastTheCutoff],
            new DateTimeImmutable($now),
            self::KEEP_DAYS,
        );

        self::assertSame([$pastTheCutoff], $expired);
    }

    public function testOnlyTheCopiesOutsideTheWindowAreReturned(): void
    {
        $ancient = $this->backup('2020-01-01');
        $justOutside = $this->backup('2026-06-12');
        $onTheCutoff = $this->backup('2026-06-13');
        $lastNight = $this->backup('2026-08-11');

        $expired = RemoteRetention::expired(
            [$ancient, $justOutside, $onTheCutoff, $lastNight],
            new DateTimeImmutable('2026-08-12 03:00:00'),
            self::KEEP_DAYS,
        );

        self::assertSame([$ancient, $justOutside], $expired);
    }

    /**
     * A retention window of 0 or less would put every copy on the deletion list,
     * last night's included — a misconfigured number acting as a wipe switch.
     * Nothing is expired instead, so the destination is never emptied by a bad
     * setting alone.
     *
     * @param int $keepDays a window that cannot be honoured
     */
    #[DataProvider('unusableWindows')]
    public function testAWindowBelowOneDayExpiresNothing(int $keepDays): void
    {
        $expired = RemoteRetention::expired(
            [$this->backup('2020-01-01'), $this->backup('2026-08-11')],
            new DateTimeImmutable('2026-08-12 03:00:00'),
            $keepDays,
        );

        self::assertSame([], $expired);
    }

    /** @return iterable<string, array{int}> */
    public static function unusableWindows(): iterable
    {
        yield 'no window at all' => [0];
        yield 'a negative window, which would put the cutoff in the future' => [-1];
        yield 'a wildly negative window' => [-3650];
    }

    /**
     * The result is consumed with `foreach` and is declared a `list`, so the keys
     * of the copies that survived filtering must not leak into it.
     */
    public function testTheResultIsAListRatherThanTheFilteredKeys(): void
    {
        $expired = RemoteRetention::expired(
            [$this->backup('2026-08-11'), $this->backup('2020-01-01')],
            new DateTimeImmutable('2026-08-12 03:00:00'),
            self::KEEP_DAYS,
        );

        self::assertSame([0], array_keys($expired));
    }

    public function testAnEmptyDestinationHasNothingToExpire(): void
    {
        self::assertSame(
            [],
            RemoteRetention::expired([], new DateTimeImmutable('2026-08-12 03:00:00'), self::KEEP_DAYS),
        );
    }

    private function backup(string $date): RemoteBackup
    {
        return new RemoteBackup(
            sprintf('homemanager-%s.sql.gz.enc', $date),
            1024,
            new DateTimeImmutable($date),
        );
    }
}
