<?php

declare(strict_types=1);

namespace App\Module\Notifications\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Notifications\Domain\ValueObject\QuietHours;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * Maps the optional {@see QuietHours} window to a single `HH:MM-HH:MM` column.
 *
 * A custom type rather than a nullable embeddable: an embeddable hydrates a
 * NULL column into a non-null object with uninitialized properties, which only
 * blows up at read time — the hazard that already forced the Series
 * `series_rating` type. This round-trips a real null both ways.
 */
final class QuietHoursType extends Type
{
    public const string NAME = 'quiet_hours';

    private const string PERSISTED_PATTERN = '/^(\d{2}:\d{2})-(\d{2}:\d{2})$/';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] = 11;

        return $platform->getStringTypeDeclarationSQL($column);
    }

    #[Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?QuietHours
    {
        if (null === $value) {
            return null;
        }

        if (1 !== preg_match(self::PERSISTED_PATTERN, (string) $value, $matches)) {
            throw new UnexpectedValueException(sprintf('Cannot read quiet hours from "%s", expected HH:MM-HH:MM.', (string) $value));
        }

        return QuietHours::fromTimes($matches[1], $matches[2]);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof QuietHours) {
            throw new InvalidArgumentException(sprintf('Expected %s, got %s.', QuietHours::class, get_debug_type($value)));
        }

        return $value->start().'-'.$value->end();
    }
}
