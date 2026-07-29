<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\Entity\Transaction;
use App\Module\Budget\Domain\Repository\TransactionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTransactionRepository implements TransactionRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Transaction $transaction): void
    {
        $this->entityManager->persist($transaction);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?Transaction
    {
        return $this->entityManager->find(Transaction::class, $id);
    }

    /** @return Transaction[] */
    public function findAll(): array
    {
        return $this->entityManager->createQuery('SELECT t FROM '.Transaction::class.' t')->getResult();
    }

    public function remove(Transaction $transaction): void
    {
        $this->entityManager->remove($transaction);
        $this->entityManager->flush();
    }
}
