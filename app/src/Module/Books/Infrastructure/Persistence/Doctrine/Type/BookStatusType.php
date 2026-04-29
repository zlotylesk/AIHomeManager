<?php

declare(strict_types=1);

namespace App\Module\Books\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Books\Domain\Enum\BookStatus;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class BookStatusType extends Type
{
    public const NAME = 'book_status';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] = 50;

        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?BookStatus
    {
        if ($value === null) {
            return null;
        }

        return BookStatus::from((string) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof BookStatus ? $value->value : (string) $value;
    }
}
