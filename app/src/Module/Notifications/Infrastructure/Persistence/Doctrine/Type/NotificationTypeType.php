<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Notifications\Domain\Enum\NotificationType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

final class NotificationTypeType extends Type
{
    public const string NAME = 'notification_type';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] = 50;

        return $platform->getStringTypeDeclarationSQL($column);
    }

    #[Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?NotificationType
    {
        if (null === $value) {
            return null;
        }

        return NotificationType::from((string) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof NotificationType ? $value->value : (string) $value;
    }
}
