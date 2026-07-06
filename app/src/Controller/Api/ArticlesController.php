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
    public function list(): JsonResponse
    {
        /** @var ArticleDTO[] $articles */
        $articles = $this->queryBus->ask(new GetAllArticles());

        return new JsonResponse($this->normalizer->normalize($articles));
    }

    #[Route('/export', methods: ['GET'])]
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
