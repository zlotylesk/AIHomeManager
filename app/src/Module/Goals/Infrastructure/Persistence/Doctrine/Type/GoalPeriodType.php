<?php

declare(strict_types=1);

namespace App\Module\Goals\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Goals\Domain\Enum\Period;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

final class GoalPeriodType extends Type
{
    public const string NAME = 'goal_period';

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
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Period
    {
        if (null === $value) {
            return null;
        }

        return Period::from((string) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof Period ? $value->value : (string) $value;
    }
}
