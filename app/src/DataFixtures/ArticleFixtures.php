<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Module\Articles\Domain\Entity\Article;
use App\Module\Articles\Domain\Repository\ArticleRepositoryInterface;
use App\Module\Articles\Domain\ValueObject\ArticleUrl;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * HMAI-39: Seeds 10 articles across 4 categories, 3 already read.
 *
 * Categories match what `articles.preferred_category` keys against, so the
 * `/api/articles/today` happy path can find an unread candidate immediately
 * after `make fixtures`.
 */
final class ArticleFixtures extends Fixture
{
    public function __construct(private readonly ArticleRepositoryInterface $repository)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $now = new DateTimeImmutable();

        $seeds = [
            ['fixture-article-1', 'Hexagonal Architecture in PHP', 'https://example.com/articles/hexagonal-php', 'programming', 12, false],
            ['fixture-article-2', 'Doctrine ORM Performance Tips', 'https://example.com/articles/doctrine-perf', 'programming', 18, false],
            ['fixture-article-3', 'CQRS Without the Hype', 'https://example.com/articles/cqrs', 'programming', 15, true],
            ['fixture-article-4', 'Modern Espresso Brewing Techniques', 'https://example.com/articles/espresso', 'lifestyle', 6, false],
            ['fixture-article-5', 'The Joy of Reading Long-form Essays', 'https://example.com/articles/long-form', 'lifestyle', 8, true],
            ['fixture-article-6', 'Symfony Messenger Patterns', 'https://example.com/articles/messenger', 'programming', 20, false],
            ['fixture-article-7', 'Sleep and Software Engineering', 'https://example.com/articles/sleep', 'wellbeing', 7, false],
            ['fixture-article-8', 'Vinyl Records: A Comeback Story', 'https://example.com/articles/vinyl', 'music', 9, false],
            ['fixture-article-9', 'Tracking Reading Habits', 'https://example.com/articles/reading-habits', 'productivity', 5, false],
            ['fixture-article-10', 'Building Healthy Routines', 'https://example.com/articles/routines', 'wellbeing', 11, true],
        ];

        foreach ($seeds as $index => [$id, $title, $url, $category, $minutes, $isRead]) {
            $this->repository->save(new Article(
                id: $id,
                title: $title,
                url: new ArticleUrl($url),
                category: $category,
                estimatedReadTime: $minutes,
                addedAt: $now->modify(sprintf('-%d days', 10 - $index)),
                readAt: $isRead ? $now->modify('-1 day') : null,
                isRead: $isRead,
            ));
        }
    }
}
