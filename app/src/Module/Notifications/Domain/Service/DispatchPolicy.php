<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\Service;

use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use DateTimeImmutable;

/**
 * Decides whether a notification type goes out right now, and over which
 * channels. Pure — no repositories, no clock, no I/O — so the rules are
 * exhaustively unit-testable; loading preferences and actually delivering
 * belongs to the Application orchestrator.
 *
 * Quiet hours **suppress** the notification rather than defer it. Deferring
 * would need delayed-message infrastructure the module does not have, and the
 * types involved age badly: a "task due" reminder held until 07:00 announces a
 * deadline that may already have passed, which is worse than not sending it.
 * The occurrence is simply not announced on that channel; the dedup key means a
 * later trigger for the same occurrence stays suppressed too.
 */
final readonly class DispatchPolicy
{
    /**
     * A never-configured type has no stored preference and no id. The default
     * state is single-sourced on the aggregate, so this transient stand-in
     * cannot drift from what the write side persists on first configuration.
     */
    private const string UNCONFIGURED_ID = 'unconfigured';

    /**
     * The channels that should carry this type at the given instant. An empty
     * list means "do not send" — the caller creates no notification at all.
     *
     * @param ?NotificationPreference $preference the stored preference, or null when the type was never configured
     *
     * @return list<Channel>
     */
    public function resolveChannels(
        NotificationType $type,
        ?NotificationPreference $preference,
        DateTimeImmutable $at,
    ): array {
        $preference ??= NotificationPreference::defaultFor(self::UNCONFIGURED_ID, $type);

        if (!$preference->isEnabled()) {
            return [];
        }

        $quietHours = $preference->quietHours();

        if (null !== $quietHours && $quietHours->covers($at)) {
            return [];
        }

        return $preference->enabledChannels();
    }
}
