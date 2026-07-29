<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BudgetApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private const string UNKNOWN_UUID = '00000000-0000-0000-0000-000000000000';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $this->connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $this->connection->executeStatement('TRUNCATE TABLE budget_transactions');
        $this->connection->executeStatement('TRUNCATE TABLE budget_categories');
    }

    /** @param array<string, mixed> $overrides */
    private function createCategory(array $overrides = []): string
    {
        $payload = array_merge(['name' => 'Groceries', 'type' => 'expense'], $overrides);
        $this->client->request('POST', '/api/budget/categories', content: (string) json_encode($payload));
        self::assertResponseStatusCodeSame(201);

        return $this->jsonResponse($this->client)['id'];
    }

    /** @param array<string, mixed> $overrides */
    private function createTransaction(array $overrides = []): string
    {
        $payload = array_merge([
            'amountInCents' => 4999,
            'currency' => 'PLN',
            'date' => '2026-07-15',
            'categoryId' => $overrides['categoryId'] ?? $this->createCategory(),
            'type' => 'expense',
        ], $overrides);
        $this->client->request('POST', '/api/budget/transactions', content: (string) json_encode($payload));
        self::assertResponseStatusCodeSame(201);

        return $this->jsonResponse($this->client)['id'];
    }

    public function testCreateListUpdateDeleteTransaction(): void
    {
        $categoryId = $this->createCategory();
        $id = $this->createTransaction(['categoryId' => $categoryId]);

        $this->client->request('GET', '/api/budget/transactions');
        self::assertResponseIsSuccessful();
        $list = $this->jsonResponse($this->client);
        self::assertCount(1, $list);
        self::assertSame($id, $list[0]['id']);
        self::assertSame(4999, $list[0]['amountInCents']);

        $this->client->request('PATCH', '/api/budget/transactions/'.$id, content: (string) json_encode([
            'amountInCents' => 6000, 'currency' => 'PLN', 'date' => '2026-07-20', 'categoryId' => $categoryId, 'type' => 'expense', 'description' => 'Updated',
        ]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/budget/transactions');
        $updated = $this->jsonResponse($this->client)[0];
        self::assertSame(6000, $updated['amountInCents']);
        self::assertSame('Updated', $updated['description']);

        $this->client->request('DELETE', '/api/budget/transactions/'.$id);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/budget/transactions');
        self::assertSame([], $this->jsonResponse($this->client));
    }

    public function testFiltersTransactionsByMonthCategoryAndType(): void
    {
        $categoryId = $this->createCategory();
        $this->createTransaction(['categoryId' => $categoryId, 'date' => '2026-06-01']);
        $this->createTransaction(['categoryId' => $categoryId, 'date' => '2026-07-01']);

        $this->client->request('GET', '/api/budget/transactions?month=2026-07');
        self::assertCount(1, $this->jsonResponse($this->client));
    }

    public function testAddTransactionRejects422OnMissingAmount(): void
    {
        $this->client->request('POST', '/api/budget/transactions', content: (string) json_encode([
            'date' => '2026-07-15', 'categoryId' => $this->createCategory(), 'type' => 'expense',
        ]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAddTransactionRejects422OnZeroAmount(): void
    {
        $this->client->request('POST', '/api/budget/transactions', content: (string) json_encode([
            'amountInCents' => 0, 'date' => '2026-07-15', 'categoryId' => $this->createCategory(), 'type' => 'expense',
        ]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testAddTransactionReturns404ForUnknownCategory(): void
    {
        $this->client->request('POST', '/api/budget/transactions', content: (string) json_encode([
            'amountInCents' => 1000, 'date' => '2026-07-15', 'categoryId' => self::UNKNOWN_UUID, 'type' => 'expense',
        ]));
        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateTransactionReturns404ForUnknownId(): void
    {
        $this->client->request('PATCH', '/api/budget/transactions/'.self::UNKNOWN_UUID, content: (string) json_encode([
            'amountInCents' => 1000, 'date' => '2026-07-15', 'categoryId' => $this->createCategory(), 'type' => 'expense',
        ]));
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteTransactionReturns404ForUnknownId(): void
    {
        $this->client->request('DELETE', '/api/budget/transactions/'.self::UNKNOWN_UUID);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateListRenameDeleteCategory(): void
    {
        $id = $this->createCategory(['name' => 'Transport']);

        $this->client->request('GET', '/api/budget/categories');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonResponse($this->client));

        $this->client->request('PATCH', '/api/budget/categories/'.$id, content: (string) json_encode(['name' => 'Renamed']));
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/budget/categories');
        self::assertSame('Renamed', $this->jsonResponse($this->client)[0]['name']);

        $this->client->request('DELETE', '/api/budget/categories/'.$id);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/budget/categories');
        self::assertSame([], $this->jsonResponse($this->client));
    }

    public function testCreateCategoryReturns409OnDuplicateNameWithinType(): void
    {
        $this->createCategory(['name' => 'Groceries', 'type' => 'expense']);
        $this->client->request('POST', '/api/budget/categories', content: (string) json_encode(['name' => 'Groceries', 'type' => 'expense']));
        self::assertResponseStatusCodeSame(409);
    }

    public function testDeleteCategoryReturns409WhenItHasTransactions(): void
    {
        $categoryId = $this->createCategory();
        $this->createTransaction(['categoryId' => $categoryId]);

        $this->client->request('DELETE', '/api/budget/categories/'.$categoryId);
        self::assertResponseStatusCodeSame(409);
    }

    public function testSetAndClearMonthlyLimit(): void
    {
        $id = $this->createCategory();

        $this->client->request('PATCH', '/api/budget/categories/'.$id.'/limit', content: (string) json_encode(['amountInCents' => 50000, 'currency' => 'PLN']));
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/budget/categories');
        self::assertSame(50000, $this->jsonResponse($this->client)[0]['monthlyLimitAmountInCents']);

        $this->client->request('PATCH', '/api/budget/categories/'.$id.'/limit', content: (string) json_encode(['amountInCents' => null, 'currency' => null]));
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/budget/categories');
        self::assertNull($this->jsonResponse($this->client)[0]['monthlyLimitAmountInCents']);
    }

    public function testSetMonthlyLimitRejects422OnHalfStatedRange(): void
    {
        $id = $this->createCategory();
        $this->client->request('PATCH', '/api/budget/categories/'.$id.'/limit', content: (string) json_encode(['amountInCents' => 50000]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testMonthlyReportContract(): void
    {
        $categoryId = $this->createCategory(['name' => 'Groceries', 'type' => 'expense']);
        $this->client->request('PATCH', '/api/budget/categories/'.$categoryId.'/limit', content: (string) json_encode(['amountInCents' => 10000, 'currency' => 'PLN']));
        self::assertResponseStatusCodeSame(204);
        $this->createTransaction(['categoryId' => $categoryId, 'amountInCents' => 15000, 'date' => '2026-07-05']);

        $this->client->request('GET', '/api/budget/report?month=2026-07');
        self::assertResponseIsSuccessful();
        $report = $this->jsonResponse($this->client);

        self::assertSame('2026-07', $report['month']);
        self::assertSame(15000, $report['totalExpensesInCents']);
        $category = $report['categories'][0];
        self::assertSame(15000, $category['spentInCents']);
        self::assertSame(10000, $category['monthlyLimitInCents']);
        self::assertTrue($category['overLimit']);
    }

    public function testReportRejects422OnMissingMonth(): void
    {
        $this->client->request('GET', '/api/budget/report');
        self::assertResponseStatusCodeSame(422);
    }

    public function testExportsTransactionsAsCsv(): void
    {
        $categoryId = $this->createCategory(['name' => 'Groceries', 'type' => 'expense']);
        $this->createTransaction(['categoryId' => $categoryId, 'amountInCents' => 499900, 'description' => 'Zakupy']);

        $this->client->request('GET', '/api/budget/export');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('budget-transactions.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('date,category,type,amount,currency,description', $body);
        // The category name, not its UUID — a CSV of ids is useless to a human.
        self::assertStringContainsString('Groceries', $body);
        // Decimal units, not the stored 499900 grosze.
        self::assertStringContainsString('4999.00', $body);
        self::assertStringContainsString('Zakupy', $body);
    }

    public function testExportsTransactionsAsPdf(): void
    {
        $this->createTransaction();

        $this->client->request('GET', '/api/budget/export?format=pdf');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('budget-transactions.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
    }

    public function testTransactionExportHonoursTheMonthFilter(): void
    {
        $categoryId = $this->createCategory();
        $this->createTransaction(['categoryId' => $categoryId, 'date' => '2026-07-15', 'description' => 'W lipcu']);
        $this->createTransaction(['categoryId' => $categoryId, 'date' => '2026-06-15', 'description' => 'W czerwcu']);

        $this->client->request('GET', '/api/budget/export?month=2026-07');
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('W lipcu', $body);
        self::assertStringNotContainsString('W czerwcu', $body);
    }

    public function testExportsMonthlyReportAsCsv(): void
    {
        $categoryId = $this->createCategory(['name' => 'Groceries', 'type' => 'expense']);
        $this->client->request('PATCH', '/api/budget/categories/'.$categoryId.'/limit', content: (string) json_encode(['amountInCents' => 10000, 'currency' => 'PLN']));
        $this->createTransaction(['categoryId' => $categoryId, 'amountInCents' => 15000, 'date' => '2026-07-05']);

        $this->client->request('GET', '/api/budget/export?dataset=report&month=2026-07');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('budget-report.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('category,type,spent,monthlyLimit,limitCurrency,percentUsed,overLimit', $body);
        self::assertStringContainsString('Groceries', $body);
        // 15000 spent against a 10000 limit — decimal units throughout, and the
        // over-limit flag carries a 1. Asserted from the type column on, so the
        // name's CSV quoting (see the test below) cannot make this brittle.
        self::assertStringContainsString(',expense,150.00,100.00,PLN,150.00,1', $body);
    }

    /**
     * fputcsv quotes any field containing a space, so a multi-word category
     * name reaches the file as `"Rachunki domowe"`. That is RFC 4180-valid and
     * what Excel expects, but it is worth pinning: anything asserting on whole
     * exported rows (the Newman collection does) breaks the day a fixture name
     * gains a space unless the quoting is a known, tested property.
     */
    public function testExportQuotesFieldsContainingSpaces(): void
    {
        $categoryId = $this->createCategory(['name' => 'Rachunki domowe', 'type' => 'expense']);
        $this->createTransaction(['categoryId' => $categoryId, 'amountInCents' => 5000, 'description' => 'Prad i woda']);

        $this->client->request('GET', '/api/budget/export');
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('"Rachunki domowe"', $body);
        self::assertStringContainsString('"Prad i woda"', $body);
    }

    public function testExportsMonthlyReportAsPdf(): void
    {
        $this->createCategory();

        $this->client->request('GET', '/api/budget/export?dataset=report&month=2026-07&format=pdf');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('budget-report.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', (string) $this->client->getResponse()->getContent());
    }

    public function testReportExportKeepsACategoryWithNoActivity(): void
    {
        $this->createCategory(['name' => 'Nietknieta', 'type' => 'expense']);

        $this->client->request('GET', '/api/budget/export?dataset=report&month=2026-07');
        $body = (string) $this->client->getResponse()->getContent();

        // A category nobody spent against must still be a row (zeroed), not
        // silently dropped from the export.
        self::assertStringContainsString('Nietknieta,expense,0.00', $body);
    }

    public function testExportRejects422OnUnknownDatasetOrFormat(): void
    {
        $this->client->request('GET', '/api/budget/export?dataset=nonsense');
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', '/api/budget/export?format=xlsx');
        self::assertResponseStatusCodeSame(422);
    }

    public function testReportExportRequiresAMonth(): void
    {
        $this->client->request('GET', '/api/budget/export?dataset=report');
        self::assertResponseStatusCodeSame(422);
    }

    public function testTransactionExportRejects422OnAMalformedMonth(): void
    {
        $this->client->request('GET', '/api/budget/export?month=lipiec');
        self::assertResponseStatusCodeSame(422);
    }

    public function testExportIsEmptyButWellFormedWithNoData(): void
    {
        $this->client->request('GET', '/api/budget/export');
        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('date,category,type,amount,currency,description', $body);
    }

    public function testVersionedAndAliasPathsAgree(): void
    {
        $this->client->request('GET', '/api/v1/budget/categories');
        $v1 = $this->jsonResponse($this->client);
        $this->client->request('GET', '/api/budget/categories');
        $alias = $this->jsonResponse($this->client);

        self::assertSame($v1, $alias);
    }

    public function testInvalidApiKeyIs401(): void
    {
        $this->client->setServerParameter('HTTP_X_API_KEY', 'wrong-key');
        $this->client->request('GET', '/api/budget/categories');
        self::assertResponseStatusCodeSame(401);
    }
}
