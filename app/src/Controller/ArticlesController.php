<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Articles\Application\Command\CreateArticle;
use App\Module\Articles\Application\Command\DeleteArticle;
use App\Module\Articles\Application\Command\MarkArticleAsRead;
use App\Module\Articles\Application\Command\UpdateArticle;
use App\Module\Articles\Application\DTO\ArticleDTO;
use App\Module\Articles\Application\Query\GetAllArticles;
use App\Module\Articles\Application\Query\GetArticleById;
use App\Module\Articles\Application\Query\GetArticleOfTheDay;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/articles')]
final class ArticlesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[Target('query.bus')] private readonly MessageBusInterface $queryBus,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        /** @var ArticleDTO[] $articles */
        $articles = $this->queryBus->dispatch(new GetAllArticles())->last(HandledStamp::class)->getResult();

        return new JsonResponse(array_map($this->serializeDTO(...), $articles));
    }

    #[Route('/today', methods: ['GET'])]
    public function today(): JsonResponse
    {
        /** @var ArticleDTO|null $dto */
        $dto = $this->queryBus->dispatch(new GetArticleOfTheDay())->last(HandledStamp::class)->getResult();

        if ($dto === null) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        return new JsonResponse($this->serializeDTO($dto));
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function detail(string $id): JsonResponse
    {
        /** @var ArticleDTO|null $dto */
        $dto = $this->queryBus->dispatch(new GetArticleById($id))->last(HandledStamp::class)->getResult();

        if ($dto === null) {
            return new JsonResponse(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeDTO($dto));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $title = trim($data['title'] ?? '');
        $url = trim($data['url'] ?? '');

        if ($title === '' || $url === '') {
            return new JsonResponse(['error' => 'Title and url are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $id = $this->commandBus->dispatch(new CreateArticle(
                title: $title,
                url: $url,
                category: $data['category'] ?? null,
                estimatedReadTime: isset($data['estimated_read_time']) ? (int) $data['estimated_read_time'] : null,
            ))->last(HandledStamp::class)->getResult();
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof \InvalidArgumentException) {
                return new JsonResponse(['error' => $e->getPrevious()->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $title = trim($data['title'] ?? '');

        if ($title === '') {
            return new JsonResponse(['error' => 'Title is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->commandBus->dispatch(new UpdateArticle(
                id: $id,
                title: $title,
                category: $data['category'] ?? null,
                estimatedReadTime: isset($data['estimated_read_time']) ? (int) $data['estimated_read_time'] : null,
            ));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof \DomainException) {
                return new JsonResponse(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function delete(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteArticle($id));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof \DomainException) {
                return new JsonResponse(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/read', methods: ['POST'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function markAsRead(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new MarkArticleAsRead($id));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof \DomainException) {
                return new JsonResponse(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function serializeDTO(ArticleDTO $dto): array
    {
        return [
            'id' => $dto->id,
            'title' => $dto->title,
            'url' => $dto->url,
            'category' => $dto->category,
            'estimatedReadTime' => $dto->estimatedReadTime,
            'addedAt' => $dto->addedAt,
            'readAt' => $dto->readAt,
            'isRead' => $dto->isRead,
        ];
    }
}
