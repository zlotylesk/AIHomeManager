<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Notifications\Domain\Enum\NotificationStatus;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

final class NotificationStatusType extends Type
{
    public const string NAME = 'notification_status';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] = 20;

        return $platform->getStringTypeDeclarationSQL($column);
    }

    #[Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?NotificationStatus
    {
        if (null === $value) {
            return null;
        }

        return NotificationStatus::from((string) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof NotificationStatus ? $value->value : (string) $value;
    }
}
