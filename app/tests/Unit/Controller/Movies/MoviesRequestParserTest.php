<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Movies;

use App\Controller\Movies\MoviesRequestParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class MoviesRequestParserTest extends TestCase
{
    private MoviesRequestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MoviesRequestParser();
    }

    public function testDecodeReturnsArrayForValidJson(): void
    {
        $request = new Request(content: '{"title":"Dune"}');

        self::assertSame(['title' => 'Dune'], $this->parser->decode($request));
    }

    public function testDecodeReturnsEmptyArrayForInvalidBody(): void
    {
        self::assertSame([], $this->parser->decode(new Request(content: 'not json')));
        self::assertSame([], $this->parser->decode(new Request(content: '')));
        self::assertSame([], $this->parser->decode(new Request(content: '"scalar"')));
    }

    public function testRequireTitleTrimsAndReturns(): void
    {
        self::assertSame('Dune', $this->parser->requireTitle(['title' => '  Dune  ']));
    }

    public function testRequireTitleRejectsMissingOrEmpty(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->parser->requireTitle(['title' => '   ']);
    }

    public function testRequireTitleRejectsNonString(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->parser->requireTitle(['title' => 123]);
    }

    public function testRequireTitleRejectsTooLong(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->parser->requireTitle(['title' => str_repeat('a', 256)]);
    }

    public function testRequireTitleAcceptsMaxLength(): void
    {
        $title = str_repeat('a', 255);

        self::assertSame($title, $this->parser->requireTitle(['title' => $title]));
    }

    public function testParseWatchedReturnsBool(): void
    {
        self::assertTrue($this->parser->parseWatched(['watched' => true]));
        self::assertFalse($this->parser->parseWatched(['watched' => false]));
    }

    public function testParseWatchedRejectsNonBool(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->parser->parseWatched(['watched' => 'yes']);
    }

    public function testParseNullableRatingAcceptsValueAndNull(): void
    {
        self::assertSame(7, $this->parser->parseNullableRating(['rating' => 7]));
        self::assertNull($this->parser->parseNullableRating(['rating' => null]));
    }

    public function testParseNullableRatingRejectsMissingKey(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->parser->parseNullableRating([]);
    }

    public function testParseNullableRatingRejectsOutOfRange(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->parser->parseNullableRating(['rating' => 11]);
    }

    public function testParseNullableRatingRejectsNonInt(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->parser->parseNullableRating(['rating' => '7']);
    }

    public function testHasMetadataFields(): void
    {
        self::assertTrue($this->parser->hasMetadataFields(['year' => 2020]));
        self::assertTrue($this->parser->hasMetadataFields(['coverUrl' => null]));
        self::assertTrue($this->parser->hasMetadataFields(['status' => 'released']));
        self::assertTrue($this->parser->hasMetadataFields(['description' => 'x']));
        self::assertFalse($this->parser->hasMetadataFields(['title' => 'Dune']));
        self::assertFalse($this->parser->hasMetadataFields([]));
    }

    public function testMetadataExtractors(): void
    {
        $data = ['coverUrl' => ' https://x.test/c.jpg ', 'year' => '2020', 'status' => ' released ', 'description' => 'A film.'];

        self::assertSame('https://x.test/c.jpg', $this->parser->metadataCoverUrl($data));
        self::assertSame(2020, $this->parser->metadataYear($data));
        self::assertSame('released', $this->parser->metadataStatus($data));
        self::assertSame('A film.', $this->parser->metadataDescription($data));
    }

    public function testMetadataExtractorsDefaultToNull(): void
    {
        self::assertNull($this->parser->metadataCoverUrl([]));
        self::assertNull($this->parser->metadataYear([]));
        self::assertNull($this->parser->metadataStatus([]));
        self::assertNull($this->parser->metadataDescription([]));
        self::assertNull($this->parser->metadataCoverUrl(['coverUrl' => '   ']));
    }
}
