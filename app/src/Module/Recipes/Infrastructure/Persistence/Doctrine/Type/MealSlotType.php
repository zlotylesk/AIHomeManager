<?php

declare(strict_types=1);

namespace App\Module\Recipes\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Recipes\Domain\Enum\MealSlot;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

/**
 * Maps the MealSlot enum to a VARCHAR column, mirroring the project's other
 * enum types (SeriesStatusType, GoalTypeType, TransactionTypeType).
 */
final class MealSlotType extends Type
{
    public const string NAME = 'meal_slot';

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
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?MealSlot
    {
        if (null === $value) {
            return null;
        }

        return MealSlot::from((string) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof MealSlot ? $value->value : (string) $value;
    }
}
