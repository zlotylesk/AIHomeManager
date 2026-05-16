<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Books\Application;

use App\Module\Books\Application\Command\AddBook;
use App\Module\Books\Application\DTO\BookMetadataDTO;
use App\Module\Books\Application\Exception\BookMetadataNotFoundException;
use App\Module\Books\Application\Handler\AddBookHandler;
use App\Module\Books\Domain\Port\BookMetadataProviderInterface;
use App\Module\Books\Domain\Repository\BookRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddBookHandlerTest extends TestCase
{
    private BookRepositoryInterface $repository;
    private BookMetadataProviderInterface $metadataProvider;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(BookRepositoryInterface::class);
        $this->metadataProvider = $this->createStub(BookMetadataProviderInterface::class);
    }

    public function testCreatesBookWithExplicitMetadataWithoutCallingProvider(): void
    {
        $provider = $this->createMock(BookMetadataProviderInterface::class);
        $provider->expects(self::never())->method('getByIsbn');

        $handler = new AddBookHandler($this->repository, $provider);
        $id = $handler(new AddBook(
            isbn: '9780306406157',
            title: 'Clean Code',
            author: 'Robert C. Martin',
            publisher: 'Prentice Hall',
            year: 2008,
            totalPages: 300,
        ));

        self::assertNotEmpty($id);
    }

    public function testCreatesBookFromMetadataProviderWhenTitleIsNull(): void
    {
        $this->metadataProvider->method('getByIsbn')->willReturn(new BookMetadataDTO(
            title: 'Clean Code',
            author: 'Robert C. Martin',
            publisher: 'Prentice Hall',
            year: 2008,
            totalPages: 300,
            coverUrl: null,
        ));

        $handler = new AddBookHandler($this->repository, $this->metadataProvider);
        $id = $handler(new AddBook(isbn: '9780306406157'));

        self::assertNotEmpty($id);
    }

    public function testUserProvidedFieldsTakePrecedenceOverApiMetadata(): void
    {
        $this->metadataProvider->method('getByIsbn')->willReturn(new BookMetadataDTO(
            title: 'API Title',
            author: 'API Author',
            publisher: 'API Publisher',
            year: 2000,
            totalPages: 100,
            coverUrl: null,
        ));

        $repository = $this->createMock(BookRepositoryInterface::class);
        $repository->expects(self::once())->method('save')->with(
            self::callback(fn ($book) => 'User Author' === $book->author())
        );

        $handler = new AddBookHandler($repository, $this->metadataProvider);
        $handler(new AddBook(isbn: '9780306406157', author: 'User Author'));
    }

    public function testThrowsWhenTotalPagesNotAvailableAfterApiLookup(): void
    {
        $this->metadataProvider->method('getByIsbn')->willReturn(new BookMetadataDTO(
            title: 'Book Without Pages',
            author: null,
            publisher: null,
            year: null,
            totalPages: null,
            coverUrl: null,
        ));

        $handler = new AddBookHandler($this->repository, $this->metadataProvider);

        $this->expectException(InvalidArgumentException::class);

        $handler(new AddBook(isbn: '9780306406157'));
    }

    public function testThrowsWhenUserSuppliesWhitespaceTitle(): void
    {
        $provider = $this->createMock(BookMetadataProviderInterface::class);
        $provider->expects(self::never())->method('getByIsbn');

        $handler = new AddBookHandler($this->repository, $provider);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field "title" is required');

        $handler(new AddBook(isbn: '9780306406157', title: '   ', totalPages: 100));
    }

    public function testPropagatesBookMetadataNotFoundException(): void
    {
        $this->metadataProvider->method('getByIsbn')
            ->willThrowException(new BookMetadataNotFoundException('Book not found in National Library.'));

        $handler = new AddBookHandler($this->repository, $this->metadataProvider);

        $this->expectException(BookMetadataNotFoundException::class);

        $handler(new AddBook(isbn: '9780306406157'));
    }
}
