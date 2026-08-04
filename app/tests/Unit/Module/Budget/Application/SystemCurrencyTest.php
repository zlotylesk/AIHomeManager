<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Budget\Application;

use App\Module\Budget\Application\SystemCurrency;
use App\Module\Budget\Domain\ValueObject\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SystemCurrencyTest extends TestCase
{
    public function testDefaultsToTheProjectsOwnCurrency(): void
    {
        self::assertSame('PLN', new SystemCurrency()->code());
    }

    public function testAcceptsAnAmountInTheConfiguredCurrency(): void
    {
        new SystemCurrency('PLN')->assertSupported(new Money(4999, 'PLN'));

        // No exception is the assertion; PHPUnit wants one anyway.
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsAnAmountInAnotherCurrency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // The message has to name both currencies: "invalid currency" leaves the
        // caller guessing which one this budget is actually kept in.
        $this->expectExceptionMessage('kept in PLN; an amount in EUR');

        new SystemCurrency('PLN')->assertSupported(new Money(4999, 'EUR'));
    }

    public function testTheAmountIsRejectedRatherThanRelabelled(): void
    {
        $money = new Money(4999, 'EUR');

        try {
            new SystemCurrency('PLN')->assertSupported($money);
            self::fail('A foreign amount must not be accepted.');
        } catch (InvalidArgumentException) {
            // Silently rewriting the code to PLN would accept bad input and
            // change its meaning — 49.99 EUR is not 49.99 PLN.
            self::assertSame('EUR', $money->currency());
        }
    }

    public function testANonPlnBudgetAcceptsItsOwnCurrencyAndRefusesPln(): void
    {
        $eur = new SystemCurrency('EUR');

        $eur->assertSupported(new Money(1000, 'EUR'));

        $this->expectException(InvalidArgumentException::class);
        $eur->assertSupported(new Money(1000, 'PLN'));
    }

    #[DataProvider('equivalentSpellingsProvider')]
    public function testCasingAndPaddingAreNormalisedOnBothSides(string $configured, string $supplied): void
    {
        // A hand-edited env value and a hand-written payload must not disagree
        // over whitespace or case — Money normalises, so this has to as well.
        new SystemCurrency($configured)->assertSupported(new Money(100, $supplied));

        $this->expectNotToPerformAssertions();
    }

    /** @return iterable<string, array{string, string}> */
    public static function equivalentSpellingsProvider(): iterable
    {
        yield 'lowercase payload' => ['PLN', 'pln'];
        yield 'lowercase config' => ['pln', 'PLN'];
        yield 'padded config' => ['  PLN  ', 'PLN'];
        yield 'both odd' => [' pLn ', ' PlN '];
    }

    #[DataProvider('unusableConfigurationProvider')]
    public function testAnUnusableConfiguredCurrencyFailsAtBootNotAtTheFirstTransaction(string $configured): void
    {
        // Left to fail later, this would reject every write in the module with a
        // message about the caller's currency, pointing at the wrong thing.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BUDGET_CURRENCY');

        new SystemCurrency($configured);
    }

    /** @return iterable<string, array{string}> */
    public static function unusableConfigurationProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['PL'];
        yield 'too long' => ['PLNN'];
        yield 'digits' => ['12'];
        yield 'symbol' => ['zł'];
    }

    public function testMatchesComparesTheSameWayAssertSupportedDoes(): void
    {
        $currency = new SystemCurrency('PLN');

        self::assertTrue($currency->matches('pln'));
        self::assertTrue($currency->matches(' PLN '));
        self::assertFalse($currency->matches('EUR'));
    }
}
