<?php

declare(strict_types=1);

/*
 * HMAI-245 — Coverage gate.
 * HMAI-357 — Coverage trend + job summary (additive; the gate itself is unchanged).
 *
 * Parses a Clover report and fails (exit 1) when line coverage drops below a
 * minimum threshold. Used by `make test-coverage` and the CI `tests` job so the
 * local and CI gate share one implementation.
 *
 * Usage: php bin/coverage-check.php <clover-file> <min-percent> [--history=PATH]
 *   e.g. php bin/coverage-check.php var/coverage/clover.xml 90
 *        php bin/coverage-check.php var/coverage/clover.xml 90 --history=var/coverage-history.txt
 *
 * With --history the previous run's percentage (a single number in PATH) is read
 * for a run-over-run trend and the current percentage is written back for the next
 * run (CI persists PATH via actions/cache). A Markdown summary is appended to the
 * file named by $GITHUB_STEP_SUMMARY when set (GitHub job summary), otherwise it is
 * printed to stdout so `make test-coverage` shows the same trend locally. None of
 * this affects the pass/fail decision below.
 */

// The documented line-coverage baseline (measured at the 1.18.0 gate, 2026-06-30).
const COVERAGE_BASELINE = 93.66;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php bin/coverage-check.php <clover-file> <min-percent> [--history=PATH]\n");
    exit(2);
}

$cloverFile = $argv[1];
$minPercent = (float) $argv[2];

$historyPath = null;
foreach (array_slice($argv, 3) as $arg) {
    if (str_starts_with($arg, '--history=')) {
        $historyPath = substr($arg, \strlen('--history='));
    }
}

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

// --- HMAI-357: trend + job summary (never influences the exit code) ------------
$previous = null;
if (null !== $historyPath && is_file($historyPath)) {
    $raw = trim((string) file_get_contents($historyPath));
    if (is_numeric($raw)) {
        $previous = (float) $raw;
    }
}

if (null !== $historyPath) {
    // Persist the current percentage for the next run's trend (CI caches PATH).
    @file_put_contents($historyPath, sprintf('%.4f', $percent));
}

$passed = !($percent + 1e-9 < $minPercent);

$summary = [];
$summary[] = '## Test coverage';
$summary[] = '';
$summary[] = '| Metric | Value |';
$summary[] = '| --- | --- |';
$summary[] = sprintf('| Current | %.2f%% (%d/%d statements) |', $percent, $covered, $statements);
$summary[] = sprintf('| Floor (gate) | %.2f%% |', $minPercent);
$summary[] = sprintf('| Baseline (1.18.0) | %.2f%% |', COVERAGE_BASELINE);
if (null !== $previous) {
    $summary[] = sprintf('| Δ vs previous run | %+.2f pp (was %.2f%%) |', $percent - $previous, $previous);
}
$summary[] = sprintf('| Δ vs baseline | %+.2f pp |', $percent - COVERAGE_BASELINE);
$summary[] = sprintf('| Status | %s |', $passed ? '✅ above floor' : '❌ below floor');
$summary[] = '';
$summaryText = implode("\n", $summary)."\n";

$summaryFile = getenv('GITHUB_STEP_SUMMARY');
if (\is_string($summaryFile) && '' !== $summaryFile) {
    @file_put_contents($summaryFile, $summaryText, FILE_APPEND);
} else {
    echo "\n".$summaryText;
}
// -------------------------------------------------------------------------------

if (!$passed) {
    fwrite(STDERR, sprintf("FAIL: coverage %.2f%% is below the %.2f%% threshold.\n", $percent, $minPercent));
    exit(1);
}

echo "OK: coverage threshold satisfied.\n";
exit(0);
