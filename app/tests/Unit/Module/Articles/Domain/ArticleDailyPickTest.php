<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Articles\Domain;

use App\Module\Articles\Domain\Entity\ArticleDailyPick;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ArticleDailyPickTest extends TestCase
{
    public function testExposesConstructorArguments(): void
    {
        $pickedAt = new DateTimeImmutable('2026-04-12 09:00:00');
        $pick = new ArticleDailyPick(
            id: 'pick-uuid',
            articleId: 'article-uuid',
            pickedAt: $pickedAt,
        );

        self::assertSame('pick-uuid', $pick->id());
        self::assertSame('article-uuid', $pick->articleId());
        self::assertSame($pickedAt, $pick->pickedAt());
    }
}
