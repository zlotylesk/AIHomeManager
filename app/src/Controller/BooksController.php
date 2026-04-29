<?php

declare(strict_types=1);

namespace App\Controller;

use App\Module\Books\Application\Command\AddBook;
use App\Module\Books\Application\Command\LogReadingSession;
use App\Module\Books\Application\Command\RemoveBook;
use App\Module\Books\Application\Command\UpdateBook;
use App\Module\Books\Application\DTO\BookDTO;
use App\Module\Books\Application\Query\GetAllBooks;
use App\Module\Books\Application\Query\GetBookDetail;
use App\Module\Books\Domain\Enum\BookStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/books')]
final class BooksController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        #[Target('query.bus')] private readonly MessageBusInterface $queryBus,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $statusParam = $request->query->get('status');

        if ($statusParam !== null && !in_array($statusParam, ['to_read', 'reading', 'completed'], true)) {
            return new JsonResponse(
                ['error' => 'Invalid status. Allowed: to_read, reading, completed.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /** @var BookDTO[] $books */
        $books = $this->queryBus->dispatch(new GetAllBooks($statusParam))->last(HandledStamp::class)->getResult();

        return new JsonResponse(array_map($this->serializeDTO(...), $books));
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function detail(string $id): JsonResponse
    {
        /** @var BookDTO|null $dto */
        $dto = $this->queryBus->dispatch(new GetBookDetail($id))->last(HandledStamp::class)->getResult();

        if ($dto === null) {
            return new JsonResponse(['error' => 'Book not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeDTO($dto));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $required = ['isbn', 'title', 'author', 'publisher', 'year', 'total_pages'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new JsonResponse(
                    ['error' => sprintf('Field "%s" is required.', $field)],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        try {
            $id = $this->commandBus->dispatch(new AddBook(
                isbn: trim($data['isbn']),
                title: trim($data['title']),
                author: trim($data['author']),
                publisher: trim($data['publisher']),
                year: (int) $data['year'],
                coverUrl: isset($data['cover_url']) ? trim($data['cover_url']) : null,
                totalPages: (int) $data['total_pages'],
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

        $required = ['title', 'author', 'publisher', 'year'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new JsonResponse(
                    ['error' => sprintf('Field "%s" is required.', $field)],
                    Response::HTTP_UNPROCESSABLE_ENTITY
                );
            }
        }

        try {
            $this->commandBus->dispatch(new UpdateBook(
                id: $id,
                title: trim($data['title']),
                author: trim($data['author']),
                publisher: trim($data['publisher']),
                year: (int) $data['year'],
                coverUrl: isset($data['cover_url']) ? trim($data['cover_url']) : null,
            ));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof \DomainException) {
                return new JsonResponse(['error' => 'Book not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function delete(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new RemoveBook($id));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof \DomainException) {
                return new JsonResponse(['error' => 'Book not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/reading-sessions', methods: ['POST'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function logReadingSession(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['pages_read']) || !is_numeric($data['pages_read'])) {
            return new JsonResponse(['error' => 'Field "pages_read" is required and must be a positive integer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $date = $data['date'] ?? date('Y-m-d');

        try {
            $this->commandBus->dispatch(new LogReadingSession(
                bookId: $id,
                pagesRead: (int) $data['pages_read'],
                date: $date,
                notes: $data['notes'] ?? null,
            ));
        } catch (HandlerFailedException $e) {
            $prev = $e->getPrevious();
            if ($prev instanceof \DomainException) {
                $message = str_contains($prev->getMessage(), 'not found') ? 'Book not found.' : $prev->getMessage();
                $status = str_contains($prev->getMessage(), 'not found') ? Response::HTTP_NOT_FOUND : Response::HTTP_UNPROCESSABLE_ENTITY;

                return new JsonResponse(['error' => $message], $status);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_CREATED);
    }

    private function serializeDTO(BookDTO $dto): array
    {
        return [
            'id' => $dto->id,
            'isbn' => $dto->isbn,
            'title' => $dto->title,
            'author' => $dto->author,
            'publisher' => $dto->publisher,
            'year' => $dto->year,
            'coverUrl' => $dto->coverUrl,
            'totalPages' => $dto->totalPages,
            'currentPage' => $dto->currentPage,
            'percentage' => $dto->percentage,
            'status' => $dto->status,
        ];
    }
}
