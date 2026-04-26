<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\Handler;

use App\Module\Articles\Application\Command\MarkArticleAsRead;
use App\Module\Articles\Domain\Repository\ArticleRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class MarkArticleAsReadHandler
{
    public function __construct(
        private ArticleRepositoryInterface $repository,
        private \Redis $redis,
    ) {}

    public function __invoke(MarkArticleAsRead $command): void
    {
        $article = $this->repository->findById($command->articleId);
        if ($article === null) {
            throw new \DomainException(sprintf('Article "%s" not found.', $command->articleId));
        }

        $article->markAsRead(new \DateTimeImmutable());
        $this->repository->save($article);

        $this->redis->del('articles:today');
    }
}
