<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\CommandHandler;

use App\Module\Notifications\Application\Command\ToggleNotificationType;
use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ToggleNotificationTypeHandler
{
    public function __construct(private NotificationPreferenceRepositoryInterface $preferences)
    {
    }

    public function __invoke(ToggleNotificationType $command): void
    {
        $type = NotificationType::tryFrom($command->type)
            ?? throw new InvalidArgumentException(sprintf('Unknown notification type "%s".', $command->type));

        $preference = $this->preferences->findByType($type)
            ?? NotificationPreference::defaultFor(Uuid::v4()->toRfc4122(), $type);

        if ($command->enabled) {
            $preference->enable();
        } else {
            $preference->disable();
        }

        $this->preferences->save($preference);
    }
}
