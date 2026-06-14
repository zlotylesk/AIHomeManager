<?php

declare(strict_types=1);

namespace App\Module\Series\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Series\Domain\ValueObject\Rating;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

/**
 * Maps the optional Rating VO to a nullable INTEGER column (`rating_value`).
 *
 * Replaces the former nullable embeddable (HMAI-220): a nullable embeddable
 * hydrates as a *non-null* object with an uninitialized backing value when the
 * column is NULL, so `rating()` would return a broken Rating instead of null —
 * fine for the write path (set-only) and the DBAL read path, but it breaks any
 * code that reads the hydrated VO (the Trakt ratings import). A custom type makes
 * null round-trip cleanly both ways, mirroring SeriesStatusType.
 */
final class RatingType extends Type
{
    public const string NAME = 'series_rating';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    #[Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Rating
    {
        if (null === $value) {
            return null;
        }

        return new Rating((int) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof Rating ? $value->value() : (int) $value;
    }
}
