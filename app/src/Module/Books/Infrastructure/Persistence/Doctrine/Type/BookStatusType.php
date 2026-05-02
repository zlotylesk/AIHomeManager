<?php

declare(strict_types=1);

namespace App\Module\Books\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Books\Domain\Enum\BookStatus;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

final class BookStatusType extends Type
{
    public const string NAME = 'book_status';

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
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?BookStatus
    {
        if (null === $value) {
            return null;
        }

        return BookStatus::from((string) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof BookStatus ? $value->value : (string) $value;
    }
}
