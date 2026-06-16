<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Application;

use App\Module\Music\Application\Service\AlbumNormalizer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AlbumNormalizerTest extends TestCase
{
    private function makeNormalizer(?LoggerInterface $logger = null): AlbumNormalizer
    {
        return new AlbumNormalizer($logger ?? new NullLogger());
    }

    public function testConvertsToLowercase(): void
    {
        self::assertSame('pink floyd the wall', $this->makeNormalizer()->normalize('Pink Floyd', 'The Wall'));
    }

    public function testRemovesParenthesesContent(): void
    {
        self::assertSame('radiohead ok computer', $this->makeNormalizer()->normalize('Radiohead', 'OK Computer (Remastered 2009)'));
    }

    public function testRemovesBracketContent(): void
    {
        self::assertSame('david bowie heroes', $this->makeNormalizer()->normalize('David Bowie', 'Heroes [Deluxe Edition]'));
    }

    public function testTrimsWhitespace(): void
    {
        self::assertSame('the beatles abbey road', $this->makeNormalizer()->normalize('  The Beatles  ', '  Abbey Road  '));
    }

    public function testNormalizesMultipleSpaces(): void
    {
        self::assertSame('pink floyd the wall', $this->makeNormalizer()->normalize('Pink  Floyd', 'The  Wall'));
    }

    public function testSameAlbumWithDifferentFormattingProducesSameKey(): void
    {
        $normalizer = $this->makeNormalizer();
        $key1 = $normalizer->normalize('Radiohead', 'OK Computer (Remastered 2009)');
        $key2 = $normalizer->normalize('Radiohead', 'OK Computer');
        $key3 = $normalizer->normalize('Radiohead', 'OK Computer [Special Edition]');

        self::assertSame($key1, $key2);
        self::assertSame($key2, $key3);
    }

    public function testRemovesSpecialCharacters(): void
    {
        $key = $this->makeNormalizer()->normalize('AC/DC', 'Back in Black');

        self::assertStringNotContainsString('/', $key);
    }

    public function testHandlesEmptyStrings(): void
    {
        $key = $this->makeNormalizer()->normalize('', '');

        self::assertSame('', $key);
    }

    public function testDoesNotLogForValidInput(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->makeNormalizer($logger)->normalize('Pink Floyd', 'The Wall');
    }

    public function testLogsPregErrorContextOnInvalidUtf8(): void
    {
        $invalidUtf8 = "\xc3\x28";

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(1))
            ->method('warning')
            ->with(
                self::stringContains('preg_replace returned null'),
                self::callback(static fn (array $ctx): bool => isset($ctx['preg_error'])
                    && isset($ctx['pattern'])
                    && 'invalid-artist' === $ctx['artist']
                    && $ctx['title'] === $invalidUtf8),
            );

        $this->makeNormalizer($logger)->normalize('invalid-artist', $invalidUtf8);
    }

    public function testInvalidUtf8InputDoesNotThrow(): void
    {
        $this->makeNormalizer()->normalize('artist', "title\xc3\x28");

        $this->addToAssertionCount(1);
    }
}
