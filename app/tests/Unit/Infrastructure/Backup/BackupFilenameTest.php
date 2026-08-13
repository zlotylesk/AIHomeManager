<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Backup;

use App\Infrastructure\Backup\BackupFilename;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Five call sites ask this class what a backup is called and when it was taken —
 * the dump writer, both destinations' pruning, and the freshness probe. Every
 * "are the backups fresh" decision in the system rests on the answer, so what is
 * pinned here is mostly the REFUSALS: the shapes that must not be read as a
 * backup, and must not be read as a date.
 */
final class BackupFilenameTest extends TestCase
{
    public function testTheNameItWritesIsTheNameItReadsBack(): void
    {
        $date = new DateTimeImmutable('2026-08-12');

        $name = BackupFilename::forDate($date);

        self::assertSame('homemanager-2026-08-12.sql.gz.enc', $name);
        self::assertEquals($date, BackupFilename::dateOf($name));
    }

    public function testTheDateIsReadWithNoTimeComponent(): void
    {
        // The '!' in the parse format matters: without it the unspecified fields
        // come from the current time, so two reads of the same filename minutes
        // apart would produce two different DateTimeImmutable values and any
        // "older than the cutoff" comparison would drift through the day.
        $date = BackupFilename::dateOf('homemanager-2026-08-12.sql.gz.enc');

        self::assertNotNull($date);
        self::assertSame('2026-08-12 00:00:00', $date->format('Y-m-d H:i:s'));
    }

    /**
     * `createFromFormat` rolls over rather than refusing nonsense, which is why
     * the parse is checked by formatting the result back. Without that check
     * `homemanager-2026-02-31…` reads as March 3rd — and since nothing else
     * bounds the date, a file that is not a backup at all could then sort as the
     * newest one present and report the backups fresh while the real dumps had
     * stopped days earlier. That is the exact shape of failure the freshness
     * checks exist to remove, so it is refused rather than rounded.
     *
     * @param string $basename a name whose date cannot be taken at face value
     */
    #[DataProvider('rollingOverDates')]
    public function testADateThatDoesNotSurviveARoundTripIsNotADate(string $basename): void
    {
        self::assertNull(BackupFilename::dateOf($basename));
    }

    /** @return iterable<string, array{string}> */
    public static function rollingOverDates(): iterable
    {
        yield 'a day past the end of the month' => ['homemanager-2026-02-31.sql.gz.enc'];
        yield 'the 31st of a 30-day month' => ['homemanager-2026-04-31.sql.gz.enc'];
        yield 'a thirteenth month' => ['homemanager-2026-13-01.sql.gz.enc'];
        yield 'a zeroth month' => ['homemanager-2026-00-10.sql.gz.enc'];
        yield 'a zeroth day' => ['homemanager-2026-08-00.sql.gz.enc'];
        yield 'February 29th in a common year' => ['homemanager-2026-02-29.sql.gz.enc'];
    }

    /**
     * Everything that is not one of ours. The plaintext case is the one worth
     * stating out loud: a leftover unencrypted dump carries a perfectly good
     * date, and reading it as a backup would let the freshness check pass on the
     * one artifact the restore path refuses to accept.
     *
     * @param string $basename a name that is not a backup this system wrote
     */
    #[DataProvider('foreignNames')]
    public function testANameThatIsNotOursHasNoDate(string $basename): void
    {
        self::assertNull(BackupFilename::dateOf($basename));
    }

    /** @return iterable<string, array{string}> */
    public static function foreignNames(): iterable
    {
        yield 'an unencrypted dump' => ['homemanager-2026-08-12.sql.gz'];
        yield 'the temporary plaintext file' => ['.homemanager-2026-08-12.sql.gz.plain'];
        yield 'another application' => ['otherapp-2026-08-12.sql.gz.enc'];
        yield 'no date at all' => ['homemanager-latest.sql.gz.enc'];
        yield 'a two-digit year' => ['homemanager-26-08-12.sql.gz.enc'];
        yield 'a timestamp appended' => ['homemanager-2026-08-12T03-00-00.sql.gz.enc'];
        yield 'a full path rather than a basename' => ['/backups/homemanager-2026-08-12.sql.gz.enc'];
        yield 'empty' => [''];
    }

    /**
     * The glob is what each reader lists the directory with, so it decides what
     * counts as a backup before any date is parsed. It must not match the
     * plaintext dump that exists for the moment it takes to encrypt one.
     *
     * @param string $basename    a candidate filename
     * @param bool   $shouldMatch whether the glob is meant to select it
     */
    #[DataProvider('globCandidates')]
    public function testTheGlobSelectsOnlyTheEncryptedArtifact(string $basename, bool $shouldMatch): void
    {
        self::assertSame($shouldMatch, fnmatch(BackupFilename::GLOB, $basename));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function globCandidates(): iterable
    {
        yield 'an encrypted backup' => ['homemanager-2026-08-12.sql.gz.enc', true];
        yield 'an unencrypted dump' => ['homemanager-2026-08-12.sql.gz', false];
        yield 'the temporary plaintext file' => ['.homemanager-2026-08-12.sql.gz.plain', false];
        yield 'another application' => ['otherapp-2026-08-12.sql.gz.enc', false];
    }
}
