<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence\Doctrine\Type;

use App\Module\Budget\Domain\ValueObject\Money;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use Override;
use UnexpectedValueException;

/**
 * Maps the two-field {@see Money} VO to a single `amount:currency` column
 * (e.g. "150000:PLN").
 *
 * A custom type rather than an embeddable: `Category::$monthlyLimit` is a
 * nullable `?Money`, and a nullable embeddable hydrates a NULL column into a
 * non-null object with uninitialized properties — the hazard that already
 * forced the Series `series_rating` type and the Notifications `QuietHours`
 * type. Used for `Transaction::$amount` too (never null) so both fields share
 * one code path and one set of tests rather than two different mappings for
 * the same VO.
 */
final class MoneyType extends Type
{
    public const string NAME = 'budget_money';

    private const string PERSISTED_PATTERN = '/^(\d+):([A-Z]{3})$/';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        $column['length'] = 32;

        return $platform->getStringTypeDeclarationSQL($column);
    }

    #[Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Money
    {
        if (null === $value) {
            return null;
        }

        if (1 !== preg_match(self::PERSISTED_PATTERN, (string) $value, $matches)) {
            throw new UnexpectedValueException(sprintf('Cannot read a money amount from "%s", expected "amount:currency".', (string) $value));
        }

        return new Money((int) $matches[1], $matches[2]);
    }

    #[Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof Money) {
            throw new InvalidArgumentException(sprintf('Expected %s, got %s.', Money::class, get_debug_type($value)));
        }

        return sprintf('%d:%s', $value->amountInCents(), $value->currency());
    }
}
