<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\Entity;

use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\ValueObject\QuietHours;
use InvalidArgumentException;

/**
 * The user's delivery preferences for one notification type: whether the type is
 * wanted at all, which channels carry it, and an optional quiet-hours window.
 *
 * The aggregate only records the preferences and answers questions about them;
 * combining them into a send/skip decision belongs to the dispatch engine.
 */
final class NotificationPreference
{
    /** @var array<string, true> keys are the values of the enabled channels */
    private array $channels = [];

    /**
     * @param list<Channel> $enabledChannels channels carrying this type (none = type reachable by no channel)
     */
    public function __construct(
        private readonly string $id,
        private readonly NotificationType $type,
        private bool $enabled = true,
        array $enabledChannels = [],
        private ?QuietHours $quietHours = null,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Notification preference id cannot be empty.');
        }

        foreach ($enabledChannels as $channel) {
            $this->channels[$channel->value] = true;
        }
    }

    /**
     * The state a type has before the user ever configures it: wanted, carried
     * by every channel, with no quiet period. Callers materialize this on the
     * first write so an unconfigured type still has something to persist.
     */
    public static function defaultFor(string $id, NotificationType $type): self
    {
        return new self(
            id: $id,
            type: $type,
            enabled: true,
            enabledChannels: Channel::cases(),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function type(): NotificationType
    {
        return $this->type;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isChannelEnabled(Channel $channel): bool
    {
        return isset($this->channels[$channel->value]);
    }

    /**
     * @return list<Channel>
     */
    public function enabledChannels(): array
    {
        return array_map(Channel::from(...), array_keys($this->channels));
    }

    public function enableChannel(Channel $channel): void
    {
        $this->channels[$channel->value] = true;
    }

    public function disableChannel(Channel $channel): void
    {
        unset($this->channels[$channel->value]);
    }

    public function quietHours(): ?QuietHours
    {
        return $this->quietHours;
    }

    /**
     * Set the quiet-hours window, or pass null to clear it (no quiet period).
     */
    public function setQuietHours(?QuietHours $quietHours): void
    {
        $this->quietHours = $quietHours;
    }
}
