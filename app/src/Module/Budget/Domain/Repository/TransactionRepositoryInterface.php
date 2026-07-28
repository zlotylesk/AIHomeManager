<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\Repository;

use App\Module\Budget\Domain\Entity\Transaction;

interface TransactionRepositoryInterface
{
    public function save(Transaction $transaction): void;

    public function findById(string $id): ?Transaction;

    /** @return Transaction[] */
    public function findAll(): array;

    public function remove(Transaction $transaction): void;
}
