<?php

declare(strict_types=1);

namespace App\Module\Movies\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Movies\Domain\ValueObject\Rating;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

/**
 * Maps the optional Rating VO to a nullable INTEGER column (`user_rating`).
 *
 * A nullable embeddable would hydrate as a *non-null* object with an
 * uninitialized backing value when the column is NULL, so `userRating()` would
 * return a broken Rating instead of null — the hazard that forced the Series
 * `series_rating` custom type (HMAI-220). A custom type makes null round-trip
 * cleanly both ways.
 */
final class RatingType extends Type
{
    public const string NAME = 'movie_rating';

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
