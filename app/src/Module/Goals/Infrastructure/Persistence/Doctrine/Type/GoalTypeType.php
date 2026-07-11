<?php

declare(strict_types=1);

namespace App\Module\Goals\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Goals\Domain\Enum\GoalType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

final class GoalTypeType extends Type
{
    public const string NAME = 'goal_type';

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
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?GoalType
    {
        if (null === $value) {
            return null;
        }

        return GoalType::from((string) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof GoalType ? $value->value : (string) $value;
    }
}
