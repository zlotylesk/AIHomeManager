<?php

declare(strict_types=1);

namespace App\Tests\Integration\Articles;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ArticlesExportApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('TRUNCATE TABLE article_daily_picks');
        $conn->executeStatement('TRUNCATE TABLE articles');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testExportReturnsCsvWithBomAndAttachmentHeader(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Klimat z polskimi znakami: ąęśćż',
            'url' => 'https://example.com/a',
            'category' => 'tech',
        ]));

        $this->client->request('GET', '/api/articles/export');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=articles.csv', $response->headers->get('Content-Disposition'));

        $body = (string) $response->getContent();

        // BOM is the first 3 bytes — Excel on Windows uses it to detect UTF-8.
        // Without it, polish diacritics would render as garbage in the export.
        self::assertSame("\xEF\xBB\xBF", substr($body, 0, 3));
        self::assertStringContainsString('title,url,category,readAt,isRead', $body);
        self::assertStringContainsString('ąęśćż', $body);
    }

    public function testExportReturnsHeaderOnlyForEmptyCollection(): void
    {
        // Acceptance criteria: export on an empty table returns only headers,
        // not an empty body — otherwise Excel opens to a blank sheet with no
        // hint about expected columns.
        $this->client->request('GET', '/api/articles/export');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertSame("\xEF\xBB\xBFtitle,url,category,readAt,isRead\n", $body);
    }

    public function testExportFiltersByStatusRead(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Read Article',
            'url' => 'https://example.com/read',
        ]));
        $readId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
        $this->client->request('POST', "/api/articles/{$readId}/read");

        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Unread Article',
            'url' => 'https://example.com/unread',
        ]));

        $this->client->request('GET', '/api/articles/export?status=read');
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Read Article', $body);
        self::assertStringNotContainsString('Unread Article', $body);
    }

    public function testExportRejectsInvalidStatusWith422(): void
    {
        $this->client->request('GET', '/api/articles/export?status=garbage');

        self::assertResponseStatusCodeSame(422);
    }

    public function testPdfExportContainsPdfMagicBytes(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'PDF test article',
            'url' => 'https://example.com/pdf',
            'category' => 'tech',
        ]));

        $this->client->request('GET', '/api/articles/export?format=pdf');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=articles.pdf', $response->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function testExportRejectsInvalidFormatWith422(): void
    {
        $this->client->request('GET', '/api/articles/export?format=xml');

        self::assertResponseStatusCodeSame(422);
    }
}
