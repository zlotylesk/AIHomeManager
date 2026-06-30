<?php

declare(strict_types=1);

/*
 * HMAI-245 — Coverage gate.
 *
 * Parses a Clover report and fails (exit 1) when line coverage drops below a
 * minimum threshold. Used by `make test-coverage` and the CI `tests` job so the
 * local and CI gate share one implementation.
 *
 * Usage: php bin/coverage-check.php <clover-file> <min-percent>
 *   e.g. php bin/coverage-check.php var/coverage/clover.xml 70
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/coverage-check.php <clover-file> <min-percent>\n");
    exit(2);
}

$cloverFile = $argv[1];
$minPercent = (float) $argv[2];

if (!is_file($cloverFile)) {
    fwrite(STDERR, sprintf("Coverage report not found: %s\n", $cloverFile));
    exit(2);
}

$xml = @simplexml_load_file($cloverFile);
if (false === $xml || !isset($xml->project->metrics)) {
    fwrite(STDERR, sprintf("Could not parse Clover metrics from: %s\n", $cloverFile));
    exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];

if (0 === $statements) {
    fwrite(STDERR, "No statements found in coverage report.\n");
    exit(2);
}

$percent = $covered / $statements * 100;

printf(
    "Line coverage: %.2f%% (%d/%d statements) — threshold %.2f%%\n",
    $percent,
    $covered,
    $statements,
    $minPercent
);

if ($percent + 1e-9 < $minPercent) {
    fwrite(STDERR, sprintf("FAIL: coverage %.2f%% is below the %.2f%% threshold.\n", $percent, $minPercent));
    exit(1);
}

echo "OK: coverage threshold satisfied.\n";
exit(0);
