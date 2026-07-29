<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Application;

use App\Module\Budget\Application\TransactionCategoryMatch;
use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Enum\TransactionType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The invariant the module documented in three places and enforced in none
 * until the epic review: a transaction's type must match its category's type.
 */
final class TransactionCategoryMatchTest extends TestCase
{
    public function testAgreeingTypesPass(): void
    {
        TransactionCategoryMatch::assertTypesAgree(
            TransactionType::EXPENSE,
            new Category('c-1', 'Zakupy', TransactionType::EXPENSE),
        );

        TransactionCategoryMatch::assertTypesAgree(
            TransactionType::INCOME,
            new Category('c-2', 'Wynagrodzenie', TransactionType::INCOME),
        );

        $this->expectNotToPerformAssertions();
    }

    public function testIncomeUnderAnExpenseCategoryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot mix income and expense');

        TransactionCategoryMatch::assertTypesAgree(
            TransactionType::INCOME,
            new Category('c-1', 'Zakupy', TransactionType::EXPENSE),
        );
    }

    public function testExpenseUnderAnIncomeCategoryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TransactionCategoryMatch::assertTypesAgree(
            TransactionType::EXPENSE,
            new Category('c-2', 'Wynagrodzenie', TransactionType::INCOME),
        );
    }

    /**
     * The message has to name both sides — a bare "type mismatch" leaves the
     * user guessing which of the two they got wrong.
     */
    public function testTheMessageNamesTheCategoryAndBothTypes(): void
    {
        try {
            TransactionCategoryMatch::assertTypesAgree(
                TransactionType::INCOME,
                new Category('c-1', 'Rachunki domowe', TransactionType::EXPENSE),
            );
            self::fail('A mismatch must be rejected.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Rachunki domowe', $e->getMessage());
            self::assertStringContainsString('income', $e->getMessage());
            self::assertStringContainsString('expense', $e->getMessage());
        }
    }
}
