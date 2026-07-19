<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\CommandHandler;

use App\Module\Notifications\Application\Command\SetQuietHours;
use App\Module\Notifications\Domain\Entity\NotificationPreference;
use App\Module\Notifications\Domain\Enum\NotificationType;
use App\Module\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Module\Notifications\Domain\ValueObject\QuietHours;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SetQuietHoursHandler
{
    public function __construct(private NotificationPreferenceRepositoryInterface $preferences)
    {
    }

    public function __invoke(SetQuietHours $command): void
    {
        $type = NotificationType::tryFrom($command->type)
            ?? throw new InvalidArgumentException(sprintf('Unknown notification type "%s".', $command->type));

        $preference = $this->preferences->findByType($type)
            ?? NotificationPreference::defaultFor(Uuid::v4()->toRfc4122(), $type);

        $preference->setQuietHours($this->buildWindow($command));

        $this->preferences->save($preference);
    }

    /**
     * Both times absent clears the window; supplying only one is a half-stated
     * range, which would silently persist as "no quiet hours" if we let it pass.
     */
    private function buildWindow(SetQuietHours $command): ?QuietHours
    {
        if (null === $command->start && null === $command->end) {
            return null;
        }

        if (null === $command->start || null === $command->end) {
            throw new InvalidArgumentException('Quiet hours need both a start and an end, or neither to clear them.');
        }

        return QuietHours::fromTimes($command->start, $command->end);
    }
}
