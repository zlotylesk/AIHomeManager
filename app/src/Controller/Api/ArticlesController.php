<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Csv\CsvBuilder;
use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\Articles\Application\Command\CreateArticle;
use App\Module\Articles\Application\Command\DeleteArticle;
use App\Module\Articles\Application\Command\MarkArticleAsRead;
use App\Module\Articles\Application\Command\UpdateArticle;
use App\Module\Articles\Application\DTO\ArticleDTO;
use App\Module\Articles\Application\Query\GetAllArticles;
use App\Module\Articles\Application\Query\GetArticleById;
use App\Module\Articles\Application\Query\GetArticleOfTheDay;
use App\Module\Articles\Application\Service\ArticleCsvExporter;
use App\Module\Articles\Application\Service\ArticleImporter;
use App\Pdf\PdfBuilder;
use DomainException;
use InvalidArgumentException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/articles')]
final class ArticlesController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly LoggerInterface $logger,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        summary: 'List articles',
        tags: ['Articles'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The list of articles.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: ArticleDTO::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function list(): JsonResponse
    {
        /** @var ArticleDTO[] $articles */
        $articles = $this->queryBus->ask(new GetAllArticles());

        return new JsonResponse($this->normalizer->normalize($articles));
    }

    #[Route('/export', methods: ['GET'])]
    #[OA\Get(
        summary: 'Export articles (CSV or PDF)',
        description: 'Streams articles (optionally filtered by read state) as a CSV or PDF attachment.',
        tags: ['Articles'],
        parameters: [
            new OA\QueryParameter(
                name: 'status',
                description: 'Filter by read state.',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['read', 'unread']),
            ),
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
    public function export(Request $request, ArticleCsvExporter $csvExporter, PdfBuilder $pdfBuilder): Response
    {
        $status = $request->query->get('status');
        if (null !== $status && !\in_array($status, ['read', 'unread'], true)) {
            return new JsonResponse(
                ['error' => 'Invalid status. Allowed: read, unread.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $format = $request->query->get('format', 'csv');
        if (!\in_array($format, ['csv', 'pdf'], true)) {
            return new JsonResponse(
                ['error' => 'Invalid format. Allowed: csv, pdf.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if ('csv' === $format) {
            return new Response(
                CsvBuilder::build(ArticleCsvExporter::HEADERS, $csvExporter->rows($status)),
                Response::HTTP_OK,
                [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename=articles.csv',
                ],
            );
        }

        $rows = [];
        foreach ($csvExporter->rows($status) as $row) {
            $rows[] = array_combine(ArticleCsvExporter::HEADERS, $row);
        }

        return new Response(
            $pdfBuilder->build('exports/articles_pdf.html.twig', ['rows' => $rows]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename=articles.pdf',
            ],
        );
    }

    #[Route('/import', methods: ['POST'])]
    #[OA\Post(
        summary: 'Import articles from a CSV file',
        description: 'Uploads a CSV of articles. Use `dry_run` to validate without persisting.',
        tags: ['Articles'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'The CSV file to import.'),
                        new OA\Property(property: 'encoding', type: 'string', nullable: true, description: 'Source encoding; auto-detected when omitted.'),
                        new OA\Property(property: 'dry_run', type: 'boolean', default: false),
                    ],
                ),
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Import summary.',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'imported', type: 'integer'),
                    new OA\Property(property: 'skipped', type: 'integer'),
                    new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'dryRun', type: 'boolean'),
                ]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function import(Request $request, ArticleImporter $importer): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(
                ['error' => 'A CSV file upload (field "file") is required.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!$file->isValid()) {
            return new JsonResponse(
                ['error' => 'File upload failed.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $encodingInput = trim($request->request->getString('encoding'));
        $encoding = '' !== $encodingInput ? $encodingInput : null;
        $dryRun = $request->request->getBoolean('dry_run');

        try {
            $result = $importer->import($file->getPathname(), $encoding, $dryRun);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'imported' => $result->imported,
            'skipped' => $result->skipped,
            'errors' => $result->errors,
            'dryRun' => $dryRun,
        ]);
    }

    #[Route('/today', methods: ['GET'])]
    #[OA\Get(
        summary: 'Article of the day',
        description: 'Returns the daily-pick article, or 204 when no pick exists yet.',
        tags: ['Articles'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The article of the day.',
                content: new OA\JsonContent(ref: new Model(type: ArticleDTO::class)),
            ),
            new OA\Response(response: 204, description: 'No article-of-the-day pick available.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function today(): JsonResponse
    {
        /** @var ArticleDTO|null $dto */
        $dto = $this->queryBus->ask(new GetArticleOfTheDay());

        if (null === $dto) {
            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        return new JsonResponse($this->normalizer->normalize($dto));
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Get(
        summary: 'Get an article by id',
        tags: ['Articles'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Article UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The article.',
                content: new OA\JsonContent(ref: new Model(type: ArticleDTO::class)),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
    public function detail(string $id): JsonResponse
    {
        /** @var ArticleDTO|null $dto */
        $dto = $this->queryBus->ask(new GetArticleById($id));

        if (null === $dto) {
            return new JsonResponse(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalizer->normalize($dto));
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        summary: 'Create an article',
        tags: ['Articles'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'url'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'How to build a mobile client'),
                    new OA\Property(property: 'url', type: 'string', format: 'uri', example: 'https://example.com/article'),
                    new OA\Property(property: 'category', type: 'string', nullable: true),
                    new OA\Property(property: 'estimated_read_time', type: 'integer', nullable: true, description: 'Minutes.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Article created.',
                content: new OA\JsonContent(required: ['id'], properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $title = trim($data['title'] ?? '');
        $url = trim($data['url'] ?? '');

        if ('' === $title || '' === $url) {
            return new JsonResponse(['error' => 'Title and url are required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $id = $this->commandBus->dispatchAndReturn(new CreateArticle(
                title: $title,
                url: $url,
                category: $data['category'] ?? null,
                estimatedReadTime: isset($data['estimated_read_time']) ? (int) $data['estimated_read_time'] : null,
            ));
        } catch (HandlerFailedException $e) {
            $original = $e->getPrevious();
            if ($original instanceof InvalidArgumentException) {
                $this->logger->warning('POST /api/articles validation failed', [
                    'message' => $original->getMessage(),
                    'exception' => $original,
                ]);

                return new JsonResponse(['error' => 'Invalid article data.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Put(
        summary: 'Update an article',
        description: 'Updates the article title, category and estimated read time (the URL is immutable).',
        tags: ['Articles'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Article UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'category', type: 'string', nullable: true),
                    new OA\Property(property: 'estimated_read_time', type: 'integer', nullable: true, description: 'Minutes.'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Article updated.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $title = trim($data['title'] ?? '');

        if ('' === $title) {
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
            $original = $e->getPrevious();
            if ($original instanceof DomainException) {
                return new JsonResponse(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
            }

            if ($original instanceof InvalidArgumentException) {
                return new JsonResponse(['error' => $original->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Delete(
        summary: 'Delete an article',
        tags: ['Articles'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Article UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Article deleted.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
    public function delete(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteArticle($id));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof DomainException) {
                return new JsonResponse(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/read', methods: ['POST'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Post(
        summary: 'Mark an article as read',
        tags: ['Articles'],
        parameters: [
            new OA\PathParameter(name: 'id', description: 'Article UUID.', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Article marked as read.'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
        ],
    )]
    public function markAsRead(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new MarkArticleAsRead($id));
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof DomainException) {
                return new JsonResponse(['error' => 'Article not found.'], Response::HTTP_NOT_FOUND);
            }
            throw $e;
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
