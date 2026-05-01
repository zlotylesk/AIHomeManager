<?php

declare(strict_types=1);

namespace App\Tests\Integration\Books;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BooksApiTest extends WebTestCase
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

    private function createBook(array $overrides = []): array
    {
        $payload = array_merge([
            'isbn' => '9780306406157',
            'title' => 'Clean Code',
            'author' => 'Robert C. Martin',
            'publisher' => 'Prentice Hall',
            'year' => 2008,
            'total_pages' => 300,
        ], $overrides);

        $this->client->request('POST', '/api/books', content: json_encode($payload));
        self::assertResponseStatusCodeSame(201);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testListBooksReturnsEmptyArray(): void
    {
        $this->client->request('GET', '/api/books');

        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testCreateBookReturns201WithId(): void
    {
        $data = $this->createBook();

        self::assertArrayHasKey('id', $data);
        self::assertNotEmpty($data['id']);
    }

    public function testCreateBookWithInvalidIsbnReturns422(): void
    {
        $this->client->request('POST', '/api/books', content: json_encode([
            'isbn' => '9780306406158',
            'title' => 'Bad ISBN',
            'author' => 'Author',
            'publisher' => 'Publisher',
            'year' => 2020,
            'total_pages' => 100,
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateBookWithMissingFieldsReturns422(): void
    {
        $this->client->request('POST', '/api/books', content: json_encode(['title' => 'No ISBN']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testGetBookDetailReturnsPercentage(): void
    {
        $created = $this->createBook(['total_pages' => 200]);
        $id = $created['id'];

        $this->client->request('GET', '/api/books/' . $id);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('percentage', $data);
        self::assertEquals(0.0, $data['percentage']);
        self::assertSame('to_read', $data['status']);
    }

    public function testGetBookDetailReturns404ForUnknownId(): void
    {
        $this->client->request('GET', '/api/books/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testListBooksFiltersByStatus(): void
    {
        $this->createBook(['isbn' => '9780306406157', 'title' => 'Book A']);
        $id2 = $this->createBook(['isbn' => '080442957X', 'title' => 'Book B', 'total_pages' => 100])['id'];

        $this->client->request('POST', '/api/books/' . $id2 . '/reading-sessions', content: json_encode([
            'pages_read' => 50,
            'date' => '2025-01-10',
        ]));

        $this->client->request('GET', '/api/books?status=reading');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
        self::assertSame('Book B', $data[0]['title']);
    }

    public function testListBooksWithInvalidStatusReturns422(): void
    {
        $this->client->request('GET', '/api/books?status=invalid');

        self::assertResponseStatusCodeSame(422);
    }

    public function testUpdateBookReturns204(): void
    {
        $id = $this->createBook()['id'];

        $this->client->request('PUT', '/api/books/' . $id, content: json_encode([
            'title' => 'Clean Code Updated',
            'author' => 'Robert C. Martin',
            'publisher' => 'Prentice Hall',
            'year' => 2009,
        ]));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/books/' . $id);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Clean Code Updated', $data['title']);
        self::assertSame(2009, $data['year']);
    }

    public function testUpdateBookReturns404ForUnknownId(): void
    {
        $this->client->request('PUT', '/api/books/00000000-0000-0000-0000-000000000000', content: json_encode([
            'title' => 'X', 'author' => 'X', 'publisher' => 'X', 'year' => 2020,
        ]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteBookReturns204(): void
    {
        $id = $this->createBook()['id'];

        $this->client->request('DELETE', '/api/books/' . $id);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/books/' . $id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteBookReturns404ForUnknownId(): void
    {
        $this->client->request('DELETE', '/api/books/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLogReadingSessionUpdatesProgressAndStatus(): void
    {
        $id = $this->createBook(['total_pages' => 200])['id'];

        $this->client->request('POST', '/api/books/' . $id . '/reading-sessions', content: json_encode([
            'pages_read' => 100,
            'date' => '2025-01-15',
        ]));

        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/books/' . $id);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(100, $data['currentPage']);
        self::assertEquals(50.0, $data['percentage']);
        self::assertSame('reading', $data['status']);
    }

    public function testLogReadingSessionCompletesBook(): void
    {
        $id = $this->createBook(['total_pages' => 100])['id'];

        $this->client->request('POST', '/api/books/' . $id . '/reading-sessions', content: json_encode([
            'pages_read' => 100,
            'date' => '2025-01-15',
        ]));

        $this->client->request('GET', '/api/books/' . $id);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('completed', $data['status']);
        self::assertEquals(100.0, $data['percentage']);
    }

    public function testLogReadingSessionReturns404ForUnknownBook(): void
    {
        $this->client->request('POST', '/api/books/00000000-0000-0000-0000-000000000000/reading-sessions', content: json_encode([
            'pages_read' => 50,
            'date' => '2025-01-15',
        ]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testLogReadingSessionReturns422WhenExceedingTotalPages(): void
    {
        $id = $this->createBook(['total_pages' => 100])['id'];

        $this->client->request('POST', '/api/books/' . $id . '/reading-sessions', content: json_encode([
            'pages_read' => 200,
            'date' => '2025-01-15',
        ]));

        self::assertResponseStatusCodeSame(422);
    }
}
