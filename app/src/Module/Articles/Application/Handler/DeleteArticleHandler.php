<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Handler;

use App\Module\Articles\Application\Command\DeleteArticle;
use App\Module\Articles\Domain\Repository\ArticleRepositoryInterface;
use DomainException;
use Redis;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeleteArticleHandler
{
    public function __construct(
        private ArticleRepositoryInterface $repository,
        private Redis $redis,
    ) {
    }

    public function __invoke(DeleteArticle $command): void
    {
        $article = $this->repository->findById($command->id);
        if (null === $article) {
            throw new DomainException(sprintf('Article "%s" not found.', $command->id));
        }

        $this->repository->delete($article);
        $this->redis->del('articles:today');
    }
}
