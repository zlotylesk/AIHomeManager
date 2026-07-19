<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\CommandHandler;

use App\Module\Notifications\Application\Command\SetChannelPreference;
use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\Channel;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SetChannelPreferenceHandler
{
    public function __construct(private NotificationPreferenceRepositoryInterface $preferences)
    {
    }

    public function __invoke(SetChannelPreference $command): void
    {
        $type = NotificationType::tryFrom($command->type)
            ?? throw new InvalidArgumentException(sprintf('Unknown notification type "%s".', $command->type));
        $channel = Channel::tryFrom($command->channel)
            ?? throw new InvalidArgumentException(sprintf('Unknown notification channel "%s".', $command->channel));

        $preference = $this->preferences->findByType($type)
            ?? NotificationPreference::defaultFor(Uuid::v4()->toRfc4122(), $type);

        if ($command->enabled) {
            $preference->enableChannel($channel);
        } else {
            $preference->disableChannel($channel);
        }

        $this->preferences->save($preference);
    }
}
