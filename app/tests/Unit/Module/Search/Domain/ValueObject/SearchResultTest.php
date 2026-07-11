<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Search\Domain\ValueObject;

use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ValueObject\SearchResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SearchResultTest extends TestCase
{
    public function testExposesFields(): void
    {
        $result = new SearchResult(SearchResultType::BOOK, '42', 'Dune', 'a desert planet', '/books/42');

        self::assertSame(SearchResultType::BOOK, $result->type);
        self::assertSame('42', $result->id);
        self::assertSame('Dune', $result->title);
        self::assertSame('a desert planet', $result->snippet);
        self::assertSame('/books/42', $result->url);
    }

    public function testAllowsEmptySnippet(): void
    {
        $result = new SearchResult(SearchResultType::TASK, '7', 'Buy milk', '', '/tasks/7');

        self::assertSame('', $result->snippet);
    }

    public function testThrowsWhenIdIsBlank(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search result id must not be empty.');

        new SearchResult(SearchResultType::BOOK, '  ', 'Dune', '', '/books/42');
    }

    public function testThrowsWhenTitleIsBlank(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search result title must not be empty.');

        new SearchResult(SearchResultType::BOOK, '42', '   ', '', '/books/42');
    }

    public function testThrowsWhenUrlIsBlank(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Search result url must not be empty.');

        new SearchResult(SearchResultType::BOOK, '42', 'Dune', '', '');
    }

    public function testEqualsIsValueBased(): void
    {
        $a = new SearchResult(SearchResultType::SERIES, '1', 'Severance', 'work-life balance', '/series/1');
        $b = new SearchResult(SearchResultType::SERIES, '1', 'Severance', 'work-life balance', '/series/1');
        $c = new SearchResult(SearchResultType::SERIES, '1', 'Severance', 'other snippet', '/series/1');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
