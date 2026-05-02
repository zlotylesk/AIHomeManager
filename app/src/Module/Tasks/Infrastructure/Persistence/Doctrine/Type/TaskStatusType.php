<?php

declare(strict_types=1);

namespace App\Module\Tasks\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Tasks\Domain\Enum\TaskStatus;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

final class TaskStatusType extends Type
{
    public const string NAME = 'task_status';

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
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?TaskStatus
    {
        if (null === $value) {
            return null;
        }

        return TaskStatus::from((string) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof TaskStatus ? $value->value : (string) $value;
    }
}
