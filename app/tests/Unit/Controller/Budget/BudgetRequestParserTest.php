<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Budget;

use App\Controller\Budget\BudgetRequestParser;
use App\Module\Budget\Application\SystemCurrency;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The Budget request parser's rules in isolation, reaching parity with
 * SeriesRequestParserTest/MoviesRequestParserTest — these were only ever
 * exercised end-to-end through BudgetApiTest, which cannot cover every
 * malformed shape without a round trip per case.
 */
final class BudgetRequestParserTest extends TestCase
{
    private BudgetRequestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new BudgetRequestParser(new SystemCurrency());
    }

    public function testDecodeReturnsAnArrayAndDegradesToEmptyOnGarbage(): void
    {
        self::assertSame(['a' => 1], $this->parser->decode(new Request(content: '{"a":1}')));
        // A non-object body is not an error here — the per-field require* rules
        // report the missing field, which is a better message than "bad JSON".
        self::assertSame([], $this->parser->decode(new Request(content: 'not json')));
        self::assertSame([], $this->parser->decode(new Request(content: '"a string"')));
        self::assertSame([], $this->parser->decode(new Request(content: '')));
    }

    /**
     * Amounts are whole minor units. A float must be refused rather than cast:
     * `49.99` silently truncating to 49 grosze is exactly the sort of quiet
     * money loss the minor-unit representation exists to prevent.
     */
    public function testAmountMustBeAnInteger(): void
    {
        self::assertSame(4999, $this->parser->requireAmountInCents(['amountInCents' => 4999]));

        foreach ([['amountInCents' => 49.99], ['amountInCents' => '4999'], ['amountInCents' => null], []] as $payload) {
            try {
                $this->parser->requireAmountInCents($payload);
                self::fail('A non-integer amount must be refused: '.json_encode($payload));
            } catch (UnprocessableEntityHttpException $e) {
                self::assertStringContainsString('amountInCents', $e->getMessage());
            }
        }
    }

    public function testCurrencyDefaultsToTheConfiguredOneAndRejectsBlanks(): void
    {
        self::assertSame('PLN', $this->parser->currency([]));
        // Still passed through unchanged — the parser only reads the shape; it is
        // the handler that refuses a currency the budget is not kept in, so the
        // 422 names the real rule instead of a missing field.
        self::assertSame('EUR', $this->parser->currency(['currency' => 'EUR']));

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->parser->currency(['currency' => '   ']);
    }

    public function testAnOmittedCurrencyFollowsTheConfigurationRatherThanAHardcodedPln(): void
    {
        // With a literal default here, a budget configured for another currency
        // would have every request that omits the field rejected by the very
        // rule meant to protect it — correct in isolation, unusable together.
        $parser = new BudgetRequestParser(new SystemCurrency('EUR'));

        self::assertSame('EUR', $parser->currency([]));
    }

    public function testRequiredStringFieldsRejectBlanksAndNonStrings(): void
    {
        self::assertSame('2026-07-15', $this->parser->requireDate(['date' => '2026-07-15']));
        self::assertSame('c-1', $this->parser->requireCategoryId(['categoryId' => 'c-1']));
        self::assertSame('expense', $this->parser->requireType(['type' => 'expense']));
        self::assertSame('Zakupy', $this->parser->requireName(['name' => 'Zakupy']));

        $rejected = [
            'date' => $this->parser->requireDate(...),
            'categoryId' => $this->parser->requireCategoryId(...),
            'type' => $this->parser->requireType(...),
            'name' => $this->parser->requireName(...),
        ];

        foreach ($rejected as $field => $parse) {
            foreach ([[], [$field => ''], [$field => '  '], [$field => 5], [$field => null]] as $payload) {
                try {
                    $parse($payload);
                    self::fail(sprintf('"%s" must be refused for %s.', $field, json_encode($payload)));
                } catch (UnprocessableEntityHttpException $e) {
                    self::assertStringContainsString($field, $e->getMessage());
                }
            }
        }
    }

    public function testDescriptionIsOptionalButMustBeAStringWhenPresent(): void
    {
        self::assertNull($this->parser->optionalDescription([]));
        self::assertNull($this->parser->optionalDescription(['description' => null]));
        self::assertSame('Weekly shop', $this->parser->optionalDescription(['description' => 'Weekly shop']));
        // An empty description is a legitimate value, not a missing one.
        self::assertSame('', $this->parser->optionalDescription(['description' => '']));

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->parser->optionalDescription(['description' => 42]);
    }

    /**
     * Both halves of the limit or neither. A half-stated range must not fall
     * through to "clear the limit" — the user asked to set one, and silently
     * persisting the opposite is the failure mode this rule exists to block.
     */
    public function testMonthlyLimitIsBothHalvesOrNeither(): void
    {
        self::assertSame([null, null], $this->parser->parseMonthlyLimit([]));
        self::assertSame([null, null], $this->parser->parseMonthlyLimit(['amountInCents' => null, 'currency' => null]));
        self::assertSame([50000, 'PLN'], $this->parser->parseMonthlyLimit(['amountInCents' => 50000, 'currency' => 'PLN']));

        foreach ([
            ['amountInCents' => 50000],
            ['currency' => 'PLN'],
            ['amountInCents' => 50000, 'currency' => null],
            ['amountInCents' => null, 'currency' => 'PLN'],
        ] as $halfStated) {
            try {
                $this->parser->parseMonthlyLimit($halfStated);
                self::fail('A half-stated limit must be refused: '.json_encode($halfStated));
            } catch (UnprocessableEntityHttpException $e) {
                self::assertStringContainsString('both', $e->getMessage());
            }
        }
    }

    public function testMonthlyLimitRejectsWronglyTypedHalves(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->parser->parseMonthlyLimit(['amountInCents' => '50000', 'currency' => 'PLN']);
    }

    public function testQueryFiltersAreOptionalAndBlankMeansAbsent(): void
    {
        $blank = new Request(['month' => ' ', 'categoryId' => '', 'type' => '']);
        self::assertNull($this->parser->optionalMonth($blank));
        self::assertNull($this->parser->optionalCategoryId($blank));
        self::assertNull($this->parser->optionalType($blank));

        $set = new Request(['month' => '2026-07', 'categoryId' => 'c-1', 'type' => 'expense']);
        self::assertSame('2026-07', $this->parser->optionalMonth($set));
        self::assertSame('c-1', $this->parser->optionalCategoryId($set));
        self::assertSame('expense', $this->parser->optionalType($set));

        self::assertNull($this->parser->optionalMonth(new Request()));
    }

    public function testRequiredMonthRejectsAMissingOrBlankValue(): void
    {
        self::assertSame('2026-07', $this->parser->requireMonth(new Request(['month' => '2026-07'])));

        foreach ([new Request(), new Request(['month' => '']), new Request(['month' => ' '])] as $request) {
            try {
                $this->parser->requireMonth($request);
                self::fail('A missing month must be refused.');
            } catch (UnprocessableEntityHttpException $e) {
                self::assertStringContainsString('month', $e->getMessage());
            }
        }
    }

    public function testExportSelectorsDefaultAndRejectUnknownValues(): void
    {
        self::assertSame('transactions', $this->parser->exportDataset(new Request()));
        self::assertSame('report', $this->parser->exportDataset(new Request(['dataset' => 'report'])));
        self::assertSame('csv', $this->parser->exportFormat(new Request()));
        self::assertSame('pdf', $this->parser->exportFormat(new Request(['format' => 'pdf'])));

        // `type` is the ledger's income/expense filter, never an export
        // selector — passing it here must not be mistaken for a dataset.
        foreach ([['dataset' => 'income'], ['dataset' => 'Transactions'], ['dataset' => '']] as $query) {
            try {
                $this->parser->exportDataset(new Request($query));
                self::fail('An unknown dataset must be refused: '.json_encode($query));
            } catch (UnprocessableEntityHttpException $e) {
                self::assertStringContainsString('dataset', $e->getMessage());
            }
        }

        foreach ([['format' => 'xlsx'], ['format' => 'CSV'], ['format' => '']] as $query) {
            try {
                $this->parser->exportFormat(new Request($query));
                self::fail('An unknown format must be refused: '.json_encode($query));
            } catch (UnprocessableEntityHttpException $e) {
                self::assertStringContainsString('format', $e->getMessage());
            }
        }
    }
}
