<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Handler;

use App\Module\Articles\Application\Command\UpdateArticle;
use App\Module\Articles\Domain\Repository\ArticleRepositoryInterface;
use DomainException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class UpdateArticleHandler
{
    public function __construct(private ArticleRepositoryInterface $repository)
    {
    }

    public function __invoke(UpdateArticle $command): void
    {
        $article = $this->repository->findById($command->id);
        if (null === $article) {
            throw new DomainException(sprintf('Article "%s" not found.', $command->id));
        }

        $article->updateMetadata($command->title, $command->category, $command->estimatedReadTime);
        $this->repository->save($article);
    }
}
