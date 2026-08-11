<?php

declare(strict_types=1);

namespace App\Tests\Support\Monitoring;

use App\Monitoring\AlertChannelInterface;
use App\Monitoring\AlertNotice;
use RuntimeException;

/**
 * Keeps every notice it was handed, and can be told to refuse them.
 *
 * Refusal is the interesting half: an alert nothing could deliver must not be
 * recorded as announced, so "the channel threw" and "the alert was sent" have
 * to be separable in a test.
 */
final class RecordingAlertChannel implements AlertChannelInterface
{
    /** @var list<AlertNotice> */
    public array $sent = [];

    private bool $rejects = false;

    public function send(AlertNotice $notice): void
    {
        if ($this->rejects) {
            throw new RuntimeException('transport unavailable');
        }

        $this->sent[] = $notice;
    }

    public function rejectEverything(): void
    {
        $this->rejects = true;
    }

    public function acceptEverything(): void
    {
        $this->rejects = false;
    }

    /** @return list<string> */
    public function sentKeys(): array
    {
        return array_map(static fn (AlertNotice $notice): string => $notice->key, $this->sent);
    }

    /** @return list<string> */
    public function sentTransitions(): array
    {
        return array_map(static fn (AlertNotice $notice): string => $notice->transition->value, $this->sent);
    }

    public function forget(): void
    {
        $this->sent = [];
    }
}
