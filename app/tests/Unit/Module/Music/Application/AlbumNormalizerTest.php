<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Application;

use App\Module\Music\Application\Service\AlbumNormalizer;
use PHPUnit\Framework\TestCase;

final class AlbumNormalizerTest extends TestCase
{
    public function testConvertsToLowercase(): void
    {
        self::assertSame('pink floyd the wall', AlbumNormalizer::normalize('Pink Floyd', 'The Wall'));
    }

    public function testRemovesParenthesesContent(): void
    {
        self::assertSame('radiohead ok computer', AlbumNormalizer::normalize('Radiohead', 'OK Computer (Remastered 2009)'));
    }

    public function testRemovesBracketContent(): void
    {
        self::assertSame('david bowie heroes', AlbumNormalizer::normalize('David Bowie', 'Heroes [Deluxe Edition]'));
    }

    public function testTrimsWhitespace(): void
    {
        self::assertSame('the beatles abbey road', AlbumNormalizer::normalize('  The Beatles  ', '  Abbey Road  '));
    }

    public function testNormalizesMultipleSpaces(): void
    {
        self::assertSame('pink floyd the wall', AlbumNormalizer::normalize('Pink  Floyd', 'The  Wall'));
    }

    public function testSameAlbumWithDifferentFormattingProducesSameKey(): void
    {
        $key1 = AlbumNormalizer::normalize('Radiohead', 'OK Computer (Remastered 2009)');
        $key2 = AlbumNormalizer::normalize('Radiohead', 'OK Computer');
        $key3 = AlbumNormalizer::normalize('Radiohead', 'OK Computer [Special Edition]');

        self::assertSame($key1, $key2);
        self::assertSame($key2, $key3);
    }

    public function testRemovesSpecialCharacters(): void
    {
        $key = AlbumNormalizer::normalize('AC/DC', 'Back in Black');

        self::assertStringNotContainsString('/', $key);
    }

    public function testHandlesEmptyStrings(): void
    {
        $key = AlbumNormalizer::normalize('', '');

        self::assertSame('', $key);
    }
}
