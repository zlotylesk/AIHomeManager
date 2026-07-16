<?php

declare(strict_types=1);

namespace App\Module\Movies\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Movies\Domain\Enum\MovieStatus;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

/**
 * Maps the nullable MovieStatus enum to a VARCHAR column. Mirrors the Series
 * SeriesStatusType; the status is optional, so null round-trips both ways.
 */
final class MovieStatusType extends Type
{
    public const string NAME = 'movie_status';

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
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?MovieStatus
    {
        if (null === $value) {
            return null;
        }

        return MovieStatus::from((string) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof MovieStatus ? $value->value : (string) $value;
    }
}
