<?php

declare(strict_types=1);

namespace App\Csv;

use RuntimeException;

/**
 * Builds an Excel-compatible CSV body (UTF-8 BOM + RFC 4180 quoting) from a
 * header row and an iterable of data rows.
 *
 * Used by /api/{books,tasks,articles}/export (HMAI-36). The cross-cutting
 * concern lives here so the BOM, fputcsv escape behavior, and stream
 * lifecycle stay consistent across modules — diverging quoting between
 * exports would surface as "the books CSV opens fine in Excel but the
 * tasks one is garbage" bug reports.
 */
final readonly class CsvBuilder
{
    /**
     * @param list<string>                     $headers
     * @param iterable<int, list<scalar|null>> $rows
     */
    public static function build(array $headers, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if (false === $handle) {
            // php://temp is in-process; failure means the runtime is in a
            // state where serving the response is hopeless anyway. Let the
            // ApiExceptionListener convert this into a 500.
            throw new RuntimeException('Could not open php://temp for CSV build.');
        }

        try {
            // UTF-8 BOM so Excel on Windows renders polish characters
            // correctly (HMAI-36 dev notes). Without it Excel interprets the
            // file as Windows-1252 and mangles every diacritic.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers, escape: '');
            foreach ($rows as $row) {
                fputcsv($handle, $row, escape: '');
            }
            rewind($handle);

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }
}
