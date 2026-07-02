<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Series;

use App\Controller\Series\SeriesRequestParser;
use App\Module\Series\Domain\Enum\SeriesStatus;
use App\Shared\Domain\ValueObject\CoverUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class SeriesRequestParserTest extends TestCase
{
    private SeriesRequestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SeriesRequestParser();
    }

    public function testDecodeReturnsAssociativeArray(): void
    {
        $request = new Request(content: '{"title":"Dexter","year":2006}');

        self::assertSame(['title' => 'Dexter', 'year' => 2006], $this->parser->decode($request));
    }

    public function testDecodeReturnsEmptyArrayForEmptyBody(): void
    {
        self::assertSame([], $this->parser->decode(new Request(content: '')));
    }

    public function testDecodeReturnsEmptyArrayForNonObjectJson(): void
    {
        self::assertSame([], $this->parser->decode(new Request(content: '"just a string"')));
    }

    public function testParseTitleTrimsValidTitle(): void
    {
        self::assertSame('Breaking Bad', $this->parser->parseTitle(['title' => '  Breaking Bad  ']));
    }

    public function testParseTitleAcceptsMaxLength(): void
    {
        $title = str_repeat('x', 255);

        self::assertSame($title, $this->parser->parseTitle(['title' => $title]));
    }

    public function testParseTitleRejectsMissing(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Title is required.');

        $this->parser->parseTitle([]);
    }

    public function testParseTitleRejectsWhitespaceOnly(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Title is required.');

        $this->parser->parseTitle(['title' => '   ']);
    }

    public function testParseTitleRejectsTooLong(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Title must be at most 255 characters.');

        $this->parser->parseTitle(['title' => str_repeat('x', 256)]);
    }

    public function testParseSeasonNumberAcceptsPositiveInt(): void
    {
        self::assertSame(3, $this->parser->parseSeasonNumber(['number' => 3]));
    }

    public function testParseSeasonNumberRejectsZero(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Season number must be a positive integer.');

        $this->parser->parseSeasonNumber(['number' => 0]);
    }

    public function testParseSeasonNumberRejectsNonInt(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Season number must be a positive integer.');

        $this->parser->parseSeasonNumber(['number' => '2']);
    }

    public function testParseEpisodeNumberAcceptsPositiveInt(): void
    {
        self::assertSame(12, $this->parser->parseEpisodeNumber(['number' => 12]));
    }

    public function testParseEpisodeNumberRejectsMissing(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Episode number must be a positive integer.');

        $this->parser->parseEpisodeNumber([]);
    }

    public function testParseOptionalEpisodeRatingReturnsNullWhenAbsent(): void
    {
        self::assertNull($this->parser->parseOptionalEpisodeRating([]));
    }

    public function testParseOptionalEpisodeRatingCastsToInt(): void
    {
        self::assertSame(7, $this->parser->parseOptionalEpisodeRating(['rating' => '7']));
    }

    public function testParseRequiredRatingAcceptsInRange(): void
    {
        self::assertSame(10, $this->parser->parseRequiredRating(['rating' => 10]));
    }

    public function testParseRequiredRatingRejectsOutOfRange(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Field "rating" must be an integer between 1 and 10.');

        $this->parser->parseRequiredRating(['rating' => 11]);
    }

    public function testParseRequiredRatingRejectsNonInt(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Field "rating" must be an integer between 1 and 10.');

        $this->parser->parseRequiredRating(['rating' => '5']);
    }

    public function testParseWatchedAcceptsBool(): void
    {
        self::assertTrue($this->parser->parseWatched(['watched' => true]));
        self::assertFalse($this->parser->parseWatched(['watched' => false]));
    }

    public function testParseWatchedRejectsNonBool(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Field "watched" must be a boolean.');

        $this->parser->parseWatched(['watched' => 'yes']);
    }

    public function testParseNullableRatingAcceptsInRange(): void
    {
        self::assertSame(8, $this->parser->parseNullableRating(['rating' => 8]));
    }

    public function testParseNullableRatingReturnsNullForExplicitNull(): void
    {
        self::assertNull($this->parser->parseNullableRating(['rating' => null]));
    }

    public function testParseNullableRatingRejectsAbsentKey(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Field "rating" must be an integer between 1 and 10, or null to clear.');

        $this->parser->parseNullableRating([]);
    }

    public function testParseNullableRatingRejectsOutOfRange(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Field "rating" must be an integer between 1 and 10, or null to clear.');

        $this->parser->parseNullableRating(['rating' => 0]);
    }

    public function testParseMetadataReturnsNormalizedBag(): void
    {
        $metadata = $this->parser->parseMetadata([
            'coverUrl' => 'https://example.com/poster.jpg',
            'year' => 2008,
            'status' => 'ended',
            'description' => '  A chemistry teacher turns to crime.  ',
        ]);

        self::assertInstanceOf(CoverUrl::class, $metadata->coverUrl);
        self::assertSame('https://example.com/poster.jpg', $metadata->coverUrl->value());
        self::assertSame(2008, $metadata->year);
        self::assertSame(SeriesStatus::ENDED, $metadata->status);
        self::assertSame('A chemistry teacher turns to crime.', $metadata->description);
        self::assertTrue($metadata->hasAnyField);
    }

    public function testParseMetadataReturnsAllNullWhenAbsent(): void
    {
        $metadata = $this->parser->parseMetadata([]);

        self::assertNull($metadata->coverUrl);
        self::assertNull($metadata->year);
        self::assertNull($metadata->status);
        self::assertNull($metadata->description);
        self::assertFalse($metadata->hasAnyField);
    }

    public function testParseMetadataFlagsPresentEvenWhenValuesAreNull(): void
    {
        $metadata = $this->parser->parseMetadata(['coverUrl' => null, 'year' => null]);

        self::assertNull($metadata->coverUrl);
        self::assertNull($metadata->year);
        self::assertTrue($metadata->hasAnyField);
    }

    public function testParseMetadataEmptyCoverUrlBecomesNull(): void
    {
        $metadata = $this->parser->parseMetadata(['coverUrl' => '   ']);

        self::assertNull($metadata->coverUrl);
        self::assertTrue($metadata->hasAnyField);
    }

    public function testParseMetadataRejectsInvalidCoverUrl(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->parser->parseMetadata(['coverUrl' => 'not-a-url']);
    }

    public function testParseMetadataRejectsYearBelowMinimum(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('year');

        $this->parser->parseMetadata(['year' => 1899]);
    }

    public function testParseMetadataRejectsYearTooFarInFuture(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('year');

        $this->parser->parseMetadata(['year' => (int) date('Y') + 6]);
    }

    public function testParseMetadataRejectsNonIntYear(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('year');

        $this->parser->parseMetadata(['year' => '2008']);
    }

    public function testParseMetadataRejectsUnknownStatus(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Field "status" must be one of: ongoing, ended.');

        $this->parser->parseMetadata(['status' => 'cancelled']);
    }

    public function testParseMetadataRejectsTooLongDescription(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Description must be at most 2000 characters.');

        $this->parser->parseMetadata(['description' => str_repeat('x', 2001)]);
    }

    public function testParseMetadataEmptyDescriptionBecomesNull(): void
    {
        $metadata = $this->parser->parseMetadata(['description' => '   ']);

        self::assertNull($metadata->description);
        self::assertTrue($metadata->hasAnyField);
    }
}
