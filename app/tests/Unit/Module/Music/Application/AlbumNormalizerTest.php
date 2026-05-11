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
        // Regression guard: clean ASCII input must not trip the regex error path
        // or iconv-fallback path. Without this, an over-eager future change could
        // log on every comparison call and flood the warning channel.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->makeNormalizer($logger)->normalize('Pink Floyd', 'The Wall');
    }

    public function testLogsPregErrorContextOnInvalidUtf8(): void
    {
        // "\xc3\x28" is the canonical PREG_BAD_UTF8_ERROR trigger: a multi-byte
        // sequence (0xC3 expects a continuation byte) followed by an ASCII '('.
        // preg_replace returns null in /u (unicode) mode.
        //
        // After the first regex bails, mb_strtolower silently substitutes the
        // invalid byte and iconv(//IGNORE) cleans the rest, so only ONE warning
        // fires. exactly(1) documents this pipeline and guards against a future
        // change that would either suppress the first warning or fail to recover
        // for the later regexes (would jump to 2 or 3 calls).
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
        // Graceful degrade: even with a regex failure, we return *some* string
        // so GetMusicComparisonHandler can still build a comparison key for the
        // rest of the albums. The key may be poor — that's better than throwing
        // and breaking the whole feature for one malformed item.
        $this->makeNormalizer()->normalize('artist', "title\xc3\x28");

        // The absence of an exception is the assertion; record it so PHPUnit
        // doesn't flag this as a risky test.
        $this->addToAssertionCount(1);
    }
}
