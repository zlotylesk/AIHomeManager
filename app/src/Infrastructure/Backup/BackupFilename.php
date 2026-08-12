<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup;

use DateTimeImmutable;

/**
 * The one place that knows what a backup file is called and how to read a date
 * back out of one.
 *
 * Five things need that answer — the job that writes the dump, the retention
 * sweep, the local freshness probe, the off-host probe and each destination's
 * own pruning — and a parse duplicated per reader is how one of them quietly
 * starts tolerating a shape the others reject. The date in particular has to be
 * read the same way everywhere, because it is what every "is the backup fresh"
 * decision in the system rests on.
 *
 * The date is taken from the NAME rather than from mtime throughout. The two
 * normally agree, but only the name survives the ways mtime lies: copying,
 * restoring or syncing a backup directory stamps every file with "now", which
 * would make a months-old set read as perfectly fresh — a wrong answer wearing
 * the shape of a right one, and precisely the failure the freshness checks
 * exist to remove. It is also the date a human types into `make restore`.
 */
final readonly class BackupFilename
{
    public const string PREFIX = 'homemanager-';
    public const string SUFFIX = '.sql.gz'.BackupCipher::FILE_EXTENSION;

    /** Matches the encrypted artifact only — a leftover plaintext dump is not a backup this system will hand to a restore. */
    public const string GLOB = self::PREFIX.'*'.self::SUFFIX;

    private const string REGEX = '/^'.self::PREFIX.'(\d{4}-\d{2}-\d{2})\.sql\.gz\.enc$/';

    public static function forDate(DateTimeImmutable $date): string
    {
        return self::PREFIX.$date->format('Y-m-d').self::SUFFIX;
    }

    /**
     * The date encoded in $basename, or null when the name is not one of ours.
     *
     * Strict, by round trip: `createFromFormat` ROLLS OVER rather than refusing
     * nonsense, so `homemanager-2026-02-31.sql.gz.enc` would silently become
     * March 3rd and could then read as newer than every real dump — a file that
     * is not a backup deciding that the backups are fresh.
     */
    public static function dateOf(string $basename): ?DateTimeImmutable
    {
        if (1 !== preg_match(self::REGEX, $basename, $matches)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $matches[1]);

        if (false === $date || $date->format('Y-m-d') !== $matches[1]) {
            return null;
        }

        return $date;
    }
}
