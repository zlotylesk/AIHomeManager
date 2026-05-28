<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Music\Domain\Enum\ListeningSource;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

final class ListeningSourceType extends Type
{
    public const string NAME = 'listening_source';

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
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?ListeningSource
    {
        if (null === $value) {
            return null;
        }

        return ListeningSource::from((string) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof ListeningSource ? $value->value : (string) $value;
    }
}
