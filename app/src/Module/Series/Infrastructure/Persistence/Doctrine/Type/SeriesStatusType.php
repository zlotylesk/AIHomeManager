<?php

declare(strict_types=1);

namespace App\Module\Series\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Series\Domain\Enum\SeriesStatus;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

/**
 * Maps the nullable SeriesStatus enum to a VARCHAR column (HMAI-190). Mirrors
 * Books' BookStatusType; the only difference is that a series status is
 * optional, so null round-trips both ways.
 */
final class SeriesStatusType extends Type
{
    public const string NAME = 'series_status';

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
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?SeriesStatus
    {
        if (null === $value) {
            return null;
        }

        return SeriesStatus::from((string) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof SeriesStatus ? $value->value : (string) $value;
    }
}
