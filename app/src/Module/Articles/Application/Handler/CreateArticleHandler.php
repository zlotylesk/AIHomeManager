<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Handler;

use App\Module\Articles\Application\Command\CreateArticle;
use App\Module\Articles\Domain\Entity\Article;
use App\Module\Articles\Domain\Repository\ArticleRepositoryInterface;
use App\Module\Articles\Domain\ValueObject\ArticleUrl;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateArticleHandler
{
    public function __construct(private ArticleRepositoryInterface $repository) {}

    public function __invoke(CreateArticle $command): string
    {
        $id = Uuid::v4()->toRfc4122();

        $this->repository->save(new Article(
            id: $id,
            title: $command->title,
            url: new ArticleUrl($command->url),
            category: $command->category,
            estimatedReadTime: $command->estimatedReadTime,
            addedAt: new \DateTimeImmutable(),
            readAt: null,
            isRead: false,
        ));

        return $id;
    }
}
