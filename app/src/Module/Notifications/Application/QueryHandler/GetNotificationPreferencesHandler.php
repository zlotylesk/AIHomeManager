<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\QueryHandler;

use App\Module\Notifications\Application\DTO\NotificationPreferenceDTO;
use App\Module\Notifications\Application\Query\GetNotificationPreferences;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Reads the preference panel via DBAL (reads never hydrate aggregates).
 *
 * Every notification type is returned, whether or not it was ever configured:
 * a type with no row falls back to the same default the write side materialises
 * (wanted, every channel, no quiet period), so the settings screen shows the
 * state that actually governs delivery rather than an empty row.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetNotificationPreferencesHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<NotificationPreferenceDTO>
     */
    public function __invoke(GetNotificationPreferences $query): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT type, enabled, channels, quiet_hours FROM notification_preferences'
        );

        $configured = [];

        foreach ($rows as $row) {
            $configured[(string) $row['type']] = $row;
        }

        $preferences = [];

        foreach (NotificationType::cases() as $type) {
            $row = $configured[$type->value] ?? null;

            if (null === $row) {
                $preferences[] = $this->unconfigured($type);
                continue;
            }

            /** @var array<string, bool> $channels */
            $channels = json_decode((string) $row['channels'], true, 512, \JSON_THROW_ON_ERROR);
            [$quietFrom, $quietTo] = $this->splitQuietHours($row['quiet_hours']);

            $preferences[] = new NotificationPreferenceDTO(
                type: $type->value,
                enabled: (bool) $row['enabled'],
                channels: array_keys($channels),
                quietFrom: $quietFrom,
                quietTo: $quietTo,
            );
        }

        return $preferences;
    }

    private function unconfigured(NotificationType $type): NotificationPreferenceDTO
    {
        return new NotificationPreferenceDTO(
            type: $type->value,
            enabled: true,
            channels: array_map(static fn (Channel $channel): string => $channel->value, Channel::cases()),
            quietFrom: null,
            quietTo: null,
        );
    }

    /**
     * The column stores the window as the custom DBAL type's "HH:MM-HH:MM"
     * string; the panel edits the two ends separately.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitQuietHours(mixed $stored): array
    {
        if (!\is_string($stored) || 1 !== preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $stored, $matches)) {
            return [null, null];
        }

        return [$matches[1], $matches[2]];
    }
}
