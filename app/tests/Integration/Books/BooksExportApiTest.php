<?php

declare(strict_types=1);

namespace App\Tests\Integration\Books;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BooksExportApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('TRUNCATE TABLE book_reading_sessions');
        $conn->executeStatement('TRUNCATE TABLE books');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testExportReturnsCsvWithBomAndAttachmentHeader(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->insert('books', [
            'id' => 'book0000-0000-0000-0000-000000000001',
            'isbn' => '9780306406157',
            'title' => 'Clean Code',
            'author' => 'Robert C. Martin',
            'publisher' => 'Prentice Hall',
            'year' => 2008,
            'total_pages' => 300,
            'current_page' => 150,
            'status' => 'reading',
        ]);

        $this->client->request('GET', '/api/books/export');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=books.csv', $response->headers->get('Content-Disposition'));

        $body = (string) $response->getContent();

        self::assertSame("\xEF\xBB\xBF", substr($body, 0, 3));
        self::assertStringContainsString('isbn,title,author,status,percentage,totalPages', $body);

        self::assertStringContainsString('9780306406157,"Clean Code","Robert C. Martin",reading,50,300', $body);
    }

    public function testExportReturnsHeaderOnlyForEmptyCollection(): void
    {
        $this->client->request('GET', '/api/books/export');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();

        self::assertSame("\xEF\xBB\xBFisbn,title,author,status,percentage,totalPages\n", $body);
    }

    public function testPdfExportContainsPdfMagicBytes(): void
    {
        $conn = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $conn->insert('books', [
            'id' => 'book0000-0000-0000-0000-000000000002',
            'isbn' => '9780306406157',
            'title' => 'Clean Code',
            'author' => 'Robert C. Martin',
            'publisher' => 'Prentice Hall',
            'year' => 2008,
            'total_pages' => 300,
            'current_page' => 150,
            'status' => 'reading',
        ]);

        $this->client->request('GET', '/api/books/export?format=pdf');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename=books.pdf', $response->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    public function testExportDefaultFormatIsCsv(): void
    {
        $this->client->request('GET', '/api/books/export');

        self::assertResponseIsSuccessful();
        self::assertSame('text/csv; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testExportRejectsInvalidFormatWith422(): void
    {
        $this->client->request('GET', '/api/books/export?format=xml');

        self::assertResponseStatusCodeSame(422);
    }
}
