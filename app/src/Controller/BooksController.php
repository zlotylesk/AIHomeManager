<?php

declare(strict_types=1);

namespace App\Controller;

use App\Csv\CsvBuilder;
use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\Books\Application\Command\AddBook;
use App\Module\Books\Application\Command\LogReadingSession;
use App\Module\Books\Application\Command\RemoveBook;
use App\Module\Books\Application\Command\UpdateBook;
use App\Module\Books\Application\DTO\BookDetailDTO;
use App\Module\Books\Application\DTO\BookDTO;
use App\Module\Books\Application\DTO\ReadingSessionDTO;
use App\Module\Books\Application\Exception\BookMetadataNotFoundException;
use App\Module\Books\Application\Exception\BookMetadataUnavailableException;
use App\Module\Books\Application\Exception\BookNotFoundException;
use App\Module\Books\Application\Query\GetAllBooks;
use App\Module\Books\Application\Query\GetBookDetail;
use App\Module\Books\Application\Service\BookCsvExporter;
use App\Module\Books\Domain\ValueObject\CoverUrl;
use App\Pdf\PdfBuilder;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/books')]
final class BooksController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $statusParam = $request->query->get('status');

        if (null !== $statusParam && !in_array($statusParam, ['to_read', 'reading', 'completed'], true)) {
            return new JsonResponse(
                ['error' => 'Invalid status. Allowed: to_read, reading, completed.'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /** @var BookDTO[] $books */
        $books = $this->queryBus->ask(new GetAllBooks($statusParam));

        return new JsonResponse(array_map($this->serializeDTO(...), $books));
    }

    #[Route('/export', methods: ['GET'])]
    public function export(Request $request, BookCsvExporter $csvExporter, PdfBuilder $pdfBuilder): Response
    {
        $format = $request->query->get('format', 'csv');
        if (!\in_array($format, ['csv', 'pdf'], true)) {
            return new JsonResponse(
                ['error' => 'Invalid format. Allowed: csv, pdf.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ('csv' === $format) {
            return new Response(
                CsvBuilder::build(BookCsvExporter::HEADERS, $csvExporter->rows()),
                Response::HTTP_OK,
                [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename=books.csv',
                ],
            );
        }

        $rows = [];
        foreach ($csvExporter->rows() as $row) {
            $rows[] = array_combine(BookCsvExporter::HEADERS, $row);
        }

        return new Response(
            $pdfBuilder->build('exports/books_pdf.html.twig', ['rows' => $rows]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename=books.pdf',
            ],
        );
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function detail(string $id): JsonResponse
    {
        /** @var BookDetailDTO|null $dto */
        $dto = $this->queryBus->ask(new GetBookDetail($id));

        if (null === $dto) {
            return new JsonResponse(['error' => 'Book not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeDetailDTO($dto));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['isbn'])) {
            return new JsonResponse(['error' => 'Field "isbn" is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $coverUrl = $this->parseCoverUrl($data['cover_url'] ?? null);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $id = $this->commandBus->dispatchAndReturn(new AddBook(
                isbn: trim((string) $data['isbn']),
                title: isset($data['title']) ? trim((string) $data['title']) : null,
                author: isset($data['author']) ? trim((string) $data['author']) : null,
                publisher: isset($data['publisher']) ? trim((string) $data['publisher']) : null,
                year: isset($data['year']) ? (int) $data['year'] : null,
                coverUrl: $coverUrl,
                totalPages: isset($data['total_pages']) ? (int) $data['total_pages'] : null,
            ));
        } catch (HandlerFailedException $e) {
            $prev = $e->getPrevious();

            if ($prev instanceof BookMetadataNotFoundException) {
                return new JsonResponse(['error' => $prev->getMessage()], Response::HTTP_NOT_FOUND);
            }

            if ($prev instanceof BookMetadataUnavailableException) {
                return new JsonResponse(['error' => $prev->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            if ($prev instanceof InvalidArgumentException) {
                return new JsonResponse(['error' => $prev->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
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
            $coverUrl = $this->parseCoverUrl($data['cover_url'] ?? null);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $this->commandBus->dispatch(new UpdateBook(
                id: $id,
                title: trim((string) $data['title']),
                author: trim((string) $data['author']),
                publisher: trim((string) $data['publisher']),
                year: (int) $data['year'],
                coverUrl: $coverUrl,
            ));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof BookNotFoundException) {
                return new JsonResponse(['error' => 'Book not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function parseCoverUrl(mixed $raw): ?CoverUrl
    {
        if (null === $raw) {
            return null;
        }

        $trimmed = trim((string) $raw);

        return '' === $trimmed ? null : new CoverUrl($trimmed);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function delete(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new RemoveBook($id));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof BookNotFoundException) {
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

        $pagesRead = $data['pages_read'] ?? null;

        if (!is_int($pagesRead) || $pagesRead <= 0) {
            return new JsonResponse(['error' => 'Field "pages_read" is required and must be a positive integer.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (array_key_exists('date', $data)) {
            $dateInput = $data['date'];
            $parsed = is_string($dateInput) ? DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput) : false;
            if (false === $parsed || $parsed->format('Y-m-d') !== $dateInput) {
                return new JsonResponse(['error' => 'Field "date" must be a valid date in Y-m-d format.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $date = $dateInput;
        } else {
            $date = date('Y-m-d');
        }

        try {
            $this->commandBus->dispatch(new LogReadingSession(
                bookId: $id,
                pagesRead: $pagesRead,
                date: $date,
                notes: $data['notes'] ?? null,
            ));
        } catch (HandlerFailedException $e) {
            $prev = $e->getPrevious();
            if ($prev instanceof BookNotFoundException) {
                return new JsonResponse(['error' => 'Book not found.'], Response::HTTP_NOT_FOUND);
            }
            if ($prev instanceof DomainException) {
                return new JsonResponse(['error' => $prev->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
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

    /**
     * @return array<string, mixed>
     */
    private function serializeDetailDTO(BookDetailDTO $dto): array
    {
        return $this->serializeDTO($dto->book) + [
            'sessions' => array_map(
                static fn (ReadingSessionDTO $session): array => [
                    'id' => $session->id,
                    'date' => $session->date,
                    'pagesRead' => $session->pagesRead,
                    'notes' => $session->notes,
                ],
                $dto->sessions,
            ),
        ];
    }
}
