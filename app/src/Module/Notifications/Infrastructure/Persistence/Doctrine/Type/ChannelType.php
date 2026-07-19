<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Notifications\Domain\Enum\Channel;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

final class ChannelType extends Type
{
    public const string NAME = 'notification_channel';

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
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Channel
    {
        if (null === $value) {
            return null;
        }

        return Channel::from((string) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof Channel ? $value->value : (string) $value;
    }
}
