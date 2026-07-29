<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Budget\BudgetRequestParser;
use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\Budget\Application\Command\AddTransaction;
use App\Module\Budget\Application\Command\CreateCategory;
use App\Module\Budget\Application\Command\DeleteCategory;
use App\Module\Budget\Application\Command\DeleteTransaction;
use App\Module\Budget\Application\Command\RenameCategory;
use App\Module\Budget\Application\Command\SetMonthlyLimit;
use App\Module\Budget\Application\Command\UpdateTransaction;
use App\Module\Budget\Application\DTO\CategoryDTO;
use App\Module\Budget\Application\DTO\TransactionDTO;
use App\Module\Budget\Application\Exception\CategoryHasTransactionsException;
use App\Module\Budget\Application\Exception\CategoryNameAlreadyTakenException;
use App\Module\Budget\Application\Exception\CategoryNotFoundException;
use App\Module\Budget\Application\Exception\TransactionNotFoundException;
use App\Module\Budget\Application\Query\GetCategories;
use App\Module\Budget\Application\Query\GetMonthlyBudgetReport;
use App\Module\Budget\Application\Query\GetTransactions;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Thin REST surface for the Budget module: reads via query.bus, writes via
 * command.bus, no domain logic (payload parsing lives in
 * BudgetRequestParser). Version-agnostic path — served under /api/v1/budget
 * and the /api/budget alias (ADR-008).
 */
#[Route('/budget')]
final class BudgetController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly NormalizerInterface $normalizer,
        private readonly BudgetRequestParser $parser,
    ) {
    }

    #[Route('/transactions', methods: ['GET'])]
    public function listTransactions(Request $request): JsonResponse
    {
        try {
            /** @var TransactionDTO[] $transactions */
            $transactions = $this->queryBus->ask(new GetTransactions(
                month: $this->parser->optionalMonth($request),
                categoryId: $this->parser->optionalCategoryId($request),
                type: $this->parser->optionalType($request),
            ));
        } catch (HandlerFailedException $e) {
            return $this->mapReadFailure($e);
        }

        return new JsonResponse($this->normalizer->normalize($transactions));
    }

    #[Route('/transactions', methods: ['POST'])]
    public function addTransaction(Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $command = new AddTransaction(
            amountInCents: $this->parser->requireAmountInCents($data),
            currency: $this->parser->currency($data),
            date: $this->parser->requireDate($data),
            categoryId: $this->parser->requireCategoryId($data),
            type: $this->parser->requireType($data),
            description: $this->parser->optionalDescription($data),
        );

        try {
            $id = $this->commandBus->dispatchAndReturn($command);
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/transactions/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function updateTransaction(string $id, Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $command = new UpdateTransaction(
            id: $id,
            amountInCents: $this->parser->requireAmountInCents($data),
            currency: $this->parser->currency($data),
            date: $this->parser->requireDate($data),
            categoryId: $this->parser->requireCategoryId($data),
            type: $this->parser->requireType($data),
            description: $this->parser->optionalDescription($data),
        );

        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/transactions/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function deleteTransaction(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteTransaction($id));
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/categories', methods: ['GET'])]
    public function listCategories(): JsonResponse
    {
        /** @var CategoryDTO[] $categories */
        $categories = $this->queryBus->ask(new GetCategories());

        return new JsonResponse($this->normalizer->normalize($categories));
    }

    #[Route('/categories', methods: ['POST'])]
    public function createCategory(Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $command = new CreateCategory(
            name: $this->parser->requireName($data),
            type: $this->parser->requireType($data),
        );

        try {
            $id = $this->commandBus->dispatchAndReturn($command);
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/categories/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function renameCategory(string $id, Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $name = $this->parser->requireName($data);

        try {
            $this->commandBus->dispatch(new RenameCategory($id, $name));
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/categories/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function deleteCategory(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteCategory($id));
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/categories/{id}/limit', methods: ['PATCH'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function setMonthlyLimit(string $id, Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        [$amountInCents, $currency] = $this->parser->parseMonthlyLimit($data);

        try {
            $this->commandBus->dispatch(new SetMonthlyLimit($id, $amountInCents, $currency));
        } catch (HandlerFailedException $e) {
            return $this->mapWriteFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/report', methods: ['GET'])]
    public function report(Request $request): JsonResponse
    {
        $month = $this->parser->requireMonth($request);

        try {
            $report = $this->queryBus->ask(new GetMonthlyBudgetReport($month));
        } catch (HandlerFailedException $e) {
            return $this->mapReadFailure($e);
        }

        return new JsonResponse($this->normalizer->normalize($report));
    }

    private function mapWriteFailure(HandlerFailedException $e): JsonResponse
    {
        $previous = $e->getPrevious();

        if ($previous instanceof TransactionNotFoundException || $previous instanceof CategoryNotFoundException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_NOT_FOUND);
        }

        if ($previous instanceof CategoryNameAlreadyTakenException || $previous instanceof CategoryHasTransactionsException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_CONFLICT);
        }

        if ($previous instanceof InvalidArgumentException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        throw $e;
    }

    private function mapReadFailure(HandlerFailedException $e): JsonResponse
    {
        $previous = $e->getPrevious();

        if ($previous instanceof InvalidArgumentException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        throw $e;
    }
}
