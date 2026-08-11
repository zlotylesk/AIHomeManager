<?php

declare(strict_types=1);

namespace App\Monitoring;

use DateTimeImmutable;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Keeps the "already announced" set in a JSON file on the local filesystem.
 *
 * The local disk is the one dependency an alert about MySQL, Redis or RabbitMQ
 * can still rely on, which is the whole reason this is a file. It is small
 * (one line per standing failure), written at most once every five minutes, and
 * read by exactly one process — none of the reasons to reach for a database
 * apply.
 *
 * Writes go through a temporary file in the same directory and a rename, so a
 * process killed mid-write leaves the previous state intact rather than a
 * truncated file. A file that cannot be parsed is treated as empty and logged:
 * the cost is re-announcing standing failures once, which is noisy but honest,
 * whereas throwing here would take the monitor down at exactly the moment it is
 * needed.
 */
final readonly class FileAlertStateStore implements AlertStateStoreInterface
{
    public function __construct(
        private string $stateFile,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function load(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }

        $raw = @file_get_contents($this->stateFile);

        if (false === $raw || '' === trim($raw)) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            $this->logger->warning('Monitoring alert state is unreadable and was discarded.', [
                'file' => $this->stateFile,
                'exception' => $failure,
            ]);

            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $alerts = [];

        foreach ($decoded as $entry) {
            $alert = $this->hydrate($entry);

            if (null !== $alert) {
                $alerts[$alert->key] = $alert;
            }
        }

        return $alerts;
    }

    public function save(array $alerts): void
    {
        $payload = array_values(array_map(
            static fn (StoredAlert $alert): array => [
                'key' => $alert->key,
                'severity' => $alert->severity->value,
                'title' => $alert->title,
                'since' => $alert->since->format(DateTimeImmutable::ATOM),
            ],
            $alerts,
        ));

        $directory = \dirname($this->stateFile);

        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create the monitoring state directory "%s".', $directory));
        }

        $temporary = @tempnam($directory, 'alert-state');

        if (false === $temporary) {
            throw new RuntimeException(sprintf('Cannot write the monitoring state file in "%s".', $directory));
        }

        try {
            $encoded = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);

            if (false === @file_put_contents($temporary, $encoded) || !@rename($temporary, $this->stateFile)) {
                throw new RuntimeException(sprintf('Cannot write the monitoring state file "%s".', $this->stateFile));
            }
        } catch (Throwable $failure) {
            @unlink($temporary);

            throw $failure;
        }
    }

    /**
     * One persisted entry, or null when it is not a shape this class wrote — a
     * hand-edited or half-migrated file costs that alert a repeat announcement
     * rather than the whole state.
     */
    private function hydrate(mixed $entry): ?StoredAlert
    {
        if (!is_array($entry)) {
            return null;
        }

        $key = $entry['key'] ?? null;
        $severity = AlertSeverity::tryFrom(is_string($entry['severity'] ?? null) ? $entry['severity'] : '');
        $title = $entry['title'] ?? null;
        $since = $entry['since'] ?? null;

        if (!is_string($key) || '' === $key || null === $severity || !is_string($title) || !is_string($since)) {
            return null;
        }

        $at = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $since);

        if (false === $at) {
            return null;
        }

        return new StoredAlert($key, $severity, $title, $at);
    }
}
