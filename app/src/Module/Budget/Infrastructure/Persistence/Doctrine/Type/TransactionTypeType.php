<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Budget\Domain\Enum\TransactionType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Override;

/**
 * Maps the TransactionType enum to a VARCHAR column. Shared by `Transaction`
 * and `Category`, both of which carry a required (non-nullable) type.
 */
final class TransactionTypeType extends Type
{
    public const string NAME = 'budget_transaction_type';

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
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?TransactionType
    {
        if (null === $value) {
            return null;
        }

        return TransactionType::from((string) $value);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof TransactionType ? $value->value : (string) $value;
    }
}
