<?php

declare(strict_types=1);

namespace App\Controller\Api;

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
use App\Pdf\PdfBuilder;
use App\Shared\Domain\ValueObject\CoverUrl;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/books')]
final class BooksController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        summary: 'List books',
        description: 'Returns all books, optionally filtered by reading status, each with page progress.',
        tags: ['Books'],
        parameters: [
            new OA\QueryParameter(
                name: 'status',
                description: 'Filter by reading status. Omit to return every book.',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['to_read', 'reading', 'completed']),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The list of books.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: BookDTO::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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

        return new JsonResponse($this->normalizer->normalize($books));
    }

    #[Route('/export', methods: ['GET'])]
    #[OA\Get(
        summary: 'Export books (CSV or PDF)',
        description: 'Streams the whole reading list as a CSV or PDF attachment.',
        tags: ['Books'],
        parameters: [
            new OA\QueryParameter(
                name: 'format',
                description: 'Export format.',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['csv', 'pdf'], default: 'csv'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The export file as an attachment.',
                content: [
                    new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string', format: 'binary')),
                    new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')),
                ],
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Get(
        summary: 'Get a book by id',
        description: 'Returns the book fields plus its logged reading sessions.',
        tags: ['Books'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Book UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The book with its reading sessions.',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: new Model(type: BookDTO::class)),
                    new OA\Schema(properties: [
                        new OA\Property(
                            property: 'sessions',
                            type: 'array',
                            items: new OA\Items(ref: new Model(type: ReadingSessionDTO::class)),
                        ),
                    ]),
                ]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
    public function detail(string $id): JsonResponse
    {
        /** @var BookDetailDTO|null $dto */
        $dto = $this->queryBus->ask(new GetBookDetail($id));

        if (null === $dto) {
            return new JsonResponse(['error' => 'Book not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalizer->normalize($dto));
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        summary: 'Add a book',
        description: 'Adds a book by ISBN. Missing fields are backfilled from an external metadata provider; 404 when the ISBN is unknown, 503 when the provider is unreachable.',
        tags: ['Books'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['isbn'],
                properties: [
                    new OA\Property(property: 'isbn', type: 'string', example: '9780134685991'),
                    new OA\Property(property: 'title', type: 'string', nullable: true),
                    new OA\Property(property: 'author', type: 'string', nullable: true),
                    new OA\Property(property: 'publisher', type: 'string', nullable: true),
                    new OA\Property(property: 'year', type: 'integer', nullable: true),
                    new OA\Property(property: 'cover_url', type: 'string', format: 'uri', nullable: true),
                    new OA\Property(property: 'total_pages', type: 'integer', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Book added.',
                content: new OA\JsonContent(required: ['id'], properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(
                response: 503,
                description: 'The book metadata provider is unavailable.',
                content: new OA\JsonContent(ref: '#/components/schemas/Error'),
            ),
        ],
    )]
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
    #[OA\Put(
        summary: 'Update a book',
        description: 'Replaces the editable book fields (title, author, publisher, year, cover).',
        tags: ['Books'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Book UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'author', 'publisher', 'year'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'author', type: 'string'),
                    new OA\Property(property: 'publisher', type: 'string'),
                    new OA\Property(property: 'year', type: 'integer'),
                    new OA\Property(property: 'cover_url', type: 'string', format: 'uri', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Book updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
    #[OA\Delete(
        summary: 'Delete a book',
        tags: ['Books'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Book UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Book deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
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
    #[OA\Post(
        summary: 'Log a reading session',
        description: 'Records pages read on a given day; advances the book progress. Defaults the date to today when omitted.',
        tags: ['Books'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Book UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['pages_read'],
                properties: [
                    new OA\Property(property: 'pages_read', type: 'integer', minimum: 1, example: 30),
                    new OA\Property(property: 'date', type: 'string', format: 'date', nullable: true, description: 'Y-m-d; defaults to today.'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 201, description: 'Reading session logged.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
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
}
