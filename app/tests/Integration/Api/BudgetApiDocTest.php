<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pins the OpenAPI contract for the Budget module, reaching parity with the
 * other modules' *ApiDocTest: every `/api/v1/budget*` operation is documented
 * and `Budget`-tagged, the read schemas expose every field the frontend
 * consumes, and the module's money-specific rules (minor units, both-or-
 * neither limit halves, the 409 guards) are part of the published contract
 * rather than folklore.
 *
 * Note on nullability: the contract is OpenAPI **3.1**, which encodes a
 * nullable field as a type union (`type: ["integer","null"]`) rather than the
 * 3.0 `nullable: true` flag — asserting the 3.0 shape here would fail against
 * a perfectly correct document.
 */
final class BudgetApiDocTest extends WebTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function budgetOperations(): array
    {
        return [
            'list transactions' => ['/api/v1/budget/transactions', 'get'],
            'create transaction' => ['/api/v1/budget/transactions', 'post'],
            'update transaction' => ['/api/v1/budget/transactions/{id}', 'patch'],
            'delete transaction' => ['/api/v1/budget/transactions/{id}', 'delete'],
            'list categories' => ['/api/v1/budget/categories', 'get'],
            'create category' => ['/api/v1/budget/categories', 'post'],
            'rename category' => ['/api/v1/budget/categories/{id}', 'patch'],
            'delete category' => ['/api/v1/budget/categories/{id}', 'delete'],
            'set monthly limit' => ['/api/v1/budget/categories/{id}/limit', 'patch'],
            'monthly report' => ['/api/v1/budget/report', 'get'],
            'export' => ['/api/v1/budget/export', 'get'],
        ];
    }

    public function testEveryBudgetOperationIsDocumentedAndTagged(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        foreach (self::budgetOperations() as $label => [$path, $method]) {
            $operation = $this->nestedArray($spec, 'paths', $path, $method);
            self::assertContains(
                'Budget',
                $operation['tags'] ?? [],
                sprintf('%s %s (%s) must be tagged "Budget".', strtoupper($method), $path, $label),
            );
        }
    }

    public function testTransactionListDocumentsItsThreeFiltersAndModel(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/budget/transactions', 'get');

        $names = array_column($get['parameters'] ?? [], 'name');
        foreach (['month', 'categoryId', 'type'] as $filter) {
            self::assertContains($filter, $names, sprintf('The "%s" filter must be documented.', $filter));
        }

        $type = $this->parameter($get, 'type');
        self::assertSame(['income', 'expense'], $type['schema']['enum'] ?? null);

        $schema = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertSame('object', $schema['type'] ?? null);
        self::assertStringContainsString('TransactionDTO', $schema['properties']['data']['items']['$ref'] ?? '');
        self::assertSame('#/components/schemas/Pagination', $schema['properties']['pagination']['$ref'] ?? null);
    }

    public function testTransactionWriteBodiesRequireTheLedgerFields(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        foreach ([['/api/v1/budget/transactions', 'post'], ['/api/v1/budget/transactions/{id}', 'patch']] as [$path, $method]) {
            $body = $this->nestedArray(
                $this->nestedArray($spec, 'paths', $path, $method),
                'requestBody',
                'content',
                'application/json',
                'schema',
            );

            self::assertSame(
                ['amountInCents', 'date', 'categoryId', 'type'],
                $body['required'] ?? null,
                sprintf('%s %s must require the four ledger fields.', strtoupper($method), $path),
            );
            // Minor units, not a decimal — a client sending 49.99 would silently
            // book 49 grosze, so the type is part of the contract, not a hint.
            self::assertSame('integer', $body['properties']['amountInCents']['type'] ?? null);
            self::assertSame(['income', 'expense'], $body['properties']['type']['enum'] ?? null);
        }

        $created = $this->nestedArray(
            $this->nestedArray($spec, 'paths', '/api/v1/budget/transactions', 'post'),
            'responses',
            '201',
            'content',
            'application/json',
            'schema',
        );
        self::assertArrayHasKey('id', $created['properties'] ?? []);
    }

    /**
     * The category guards are 409s, not 404s or silent successes, and a client
     * cannot distinguish them unless the contract says so: a duplicate name
     * within a type, and a delete refused because the ledger still references
     * the category.
     */
    public function testCategoryConflictGuardsArePartOfTheContract(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        foreach ([['/api/v1/budget/categories', 'post'], ['/api/v1/budget/categories/{id}', 'patch'], ['/api/v1/budget/categories/{id}', 'delete']] as [$path, $method]) {
            $conflict = $this->nestedArray($this->nestedArray($spec, 'paths', $path, $method), 'responses', '409');
            self::assertStringContainsString(
                'ConflictError',
                $conflict['$ref'] ?? '',
                sprintf('%s %s must document its 409 via the shared component.', strtoupper($method), $path),
            );
        }

        $delete = $this->nestedArray($spec, 'paths', '/api/v1/budget/categories/{id}', 'delete');
        self::assertStringContainsString(
            'transaction',
            strtolower($delete['description'] ?? ''),
            'The delete guard must say why it refuses, so a client can tell the user what to do about it.',
        );
    }

    /**
     * Both halves of the limit must be settable to null together — the contract
     * has to allow that, otherwise a client generated from it could never clear
     * a limit it was able to set.
     */
    public function testMonthlyLimitBodyAllowsBothHalvesToBeNull(): void
    {
        $patch = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/budget/categories/{id}/limit', 'patch');

        $properties = $this->nestedArray($patch, 'requestBody', 'content', 'application/json', 'schema')['properties'] ?? [];

        // OpenAPI 3.1 encodes nullability as a type union, not `nullable: true`.
        self::assertSame(['integer', 'null'], $properties['amountInCents']['type'] ?? null);
        self::assertSame(['string', 'null'], $properties['currency']['type'] ?? null);
        self::assertArrayNotHasKey('required', $this->nestedArray($patch, 'requestBody', 'content', 'application/json', 'schema'));
    }

    public function testReportRequiresItsMonthAndReturnsTheReportModel(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/budget/report', 'get');

        $month = $this->parameter($get, 'month');
        self::assertTrue($month['required'] ?? false, 'The report cannot default a month — it must be required.');

        $schema = $this->nestedArray($get, 'responses', '200', 'content', 'application/json', 'schema');
        self::assertStringContainsString('MonthlyBudgetReportDTO', $schema['$ref'] ?? '');
    }

    public function testExportOffersBothDatasetsInBothFormats(): void
    {
        $get = $this->nestedArray($this->fetchSpec(static::createClient()), 'paths', '/api/v1/budget/export', 'get');

        // `dataset`, not `type` — the ledger already spends `type` on
        // income-vs-expense, so a second meaning on the same endpoint would be
        // ambiguous for anyone reading the contract.
        self::assertSame(['transactions', 'report'], $this->parameter($get, 'dataset')['schema']['enum'] ?? null);
        self::assertSame(['csv', 'pdf'], $this->parameter($get, 'format')['schema']['enum'] ?? null);

        $content = $this->nestedArray($get, 'responses', '200', 'content');
        self::assertArrayHasKey('text/csv', $content);
        self::assertArrayHasKey('application/pdf', $content);
        self::assertSame('binary', $content['application/pdf']['schema']['format'] ?? null);
    }

    /**
     * The read schemas must mirror the normalizers field-for-field — the
     * runtime conformance gate validates real responses against exactly these.
     */
    public function testReadModelsExposeEveryNormalizedField(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        $expected = [
            'TransactionDTO' => ['id', 'amountInCents', 'currency', 'date', 'categoryId', 'type', 'description'],
            'CategoryDTO' => ['id', 'name', 'type', 'monthlyLimitAmountInCents', 'monthlyLimitCurrency'],
            'MonthlyBudgetReportDTO' => ['month', 'currency', 'totalIncomeInCents', 'totalExpensesInCents', 'balanceInCents', 'categories'],
            'CategoryBudgetDTO' => ['categoryId', 'categoryName', 'type', 'spentInCents', 'monthlyLimitInCents', 'monthlyLimitCurrency', 'percentUsed', 'overLimit'],
        ];

        foreach ($expected as $model => $fields) {
            $properties = $this->nestedArray($spec, 'components', 'schemas', $model)['properties'] ?? [];
            self::assertIsArray($properties);
            foreach ($fields as $field) {
                self::assertArrayHasKey($field, $properties, sprintf('%s must document the "%s" field.', $model, $field));
            }
        }

        // The report's breakdown must $ref the nested model rather than inlining
        // an anonymous shape, so a client generates one CategoryBudget type.
        $categories = $this->nestedArray($spec, 'components', 'schemas', 'MonthlyBudgetReportDTO', 'properties', 'categories');
        self::assertStringContainsString('CategoryBudgetDTO', $categories['items']['$ref'] ?? '');

        // percentUsed is null for an unlimited category — a distinct state from
        // 0%, and a client that types it as a plain number would break on it.
        $percent = $this->nestedArray($spec, 'components', 'schemas', 'CategoryBudgetDTO', 'properties', 'percentUsed');
        self::assertSame(['number', 'null'], $percent['type'] ?? null);

        // The report's own currency is never null: every figure in it is stated
        // in the one currency the budget is kept in, so a client can label them
        // without guessing.
        $reportCurrency = $this->nestedArray($spec, 'components', 'schemas', 'MonthlyBudgetReportDTO', 'properties', 'currency');
        self::assertSame('string', $reportCurrency['type'] ?? null);
    }

    public function testTheSingleCurrencyRuleIsDocumentedWhereverAnAmountIsAccepted(): void
    {
        // A client reading the contract must learn that a foreign currency is a
        // 422 before it sends one — the rule is invisible in the schema types,
        // which allow any string.
        $spec = $this->fetchSpec(static::createClient());

        $operations = [
            ['/api/v1/budget/transactions', 'post'],
            ['/api/v1/budget/transactions/{id}', 'patch'],
            ['/api/v1/budget/categories/{id}/limit', 'patch'],
        ];

        foreach ($operations as [$path, $method]) {
            $properties = $this->nestedArray(
                $spec,
                'paths',
                $path,
                $method,
                'requestBody',
                'content',
                'application/json',
                'schema',
                'properties',
            );

            self::assertArrayHasKey('currency', $properties, sprintf('%s %s must document a currency field.', strtoupper($method), $path));
            self::assertIsArray($properties['currency']);
            self::assertStringContainsString(
                '422',
                (string) ($properties['currency']['description'] ?? ''),
                sprintf('%s %s must state that another currency is rejected.', strtoupper($method), $path),
            );
        }
    }

    public function testTheSpecDeclaresTheBudgetTag(): void
    {
        $spec = $this->fetchSpec(static::createClient());

        $names = array_column($spec['tags'] ?? [], 'name');
        self::assertContains('Budget', $names);
    }

    /**
     * @param array<mixed> $operation
     *
     * @return array<mixed>
     */
    private function parameter(array $operation, string $name): array
    {
        foreach ($operation['parameters'] ?? [] as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === $name) {
                return $parameter;
            }
        }

        self::fail(sprintf('The "%s" parameter is not documented.', $name));
    }

    /**
     * @return array<mixed>
     */
    private function fetchSpec(KernelBrowser $client): array
    {
        $client->request('GET', '/api/doc.json');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), 'The OpenAPI spec must be reachable without an API key.');

        $content = $response->getContent();
        self::assertIsString($content);
        $doc = json_decode($content, true);
        self::assertIsArray($doc);

        return $doc;
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<mixed>
     */
    private function nestedArray(array $tree, string ...$keys): array
    {
        $node = $tree;
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $node, sprintf('Missing "%s" in the OpenAPI document.', $key));
            self::assertIsArray($node[$key], sprintf('"%s" must be an object in the OpenAPI document.', $key));
            $node = $node[$key];
        }

        return $node;
    }
}
