<?php

declare(strict_types=1);

namespace App\Monitoring;

use InvalidArgumentException;

/**
 * One monitored state that is currently wrong.
 *
 * A probe reports a *local* key ("mysql", "stale"); {@see SystemMonitor}
 * namespaces it with the probe's name before anything else sees it, so two
 * probes cannot collide on a key and a probe cannot claim another's namespace
 * by choosing a clever string. That namespacing is what lets the monitor decide
 * which stored alerts a silent probe is allowed to leave un-resolved.
 */
final readonly class Alert
{
    public function __construct(
        public string $key,
        public AlertSeverity $severity,
        public string $title,
        public string $detail,
    ) {
        foreach (['key' => $key, 'title' => $title] as $label => $part) {
            if ('' === trim($part)) {
                throw new InvalidArgumentException(sprintf('Alert %s cannot be empty.', $label));
            }
        }
    }

    /**
     * The same alert, reported under the probe's namespace.
     */
    public function namespacedIn(string $probeName): self
    {
        return new self($probeName.':'.$this->key, $this->severity, $this->title, $this->detail);
    }
}
