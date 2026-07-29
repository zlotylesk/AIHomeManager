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

    /**
     * Whether at least one transaction is attributed to this category — the
     * guard behind the "cannot delete a category with transactions" rule.
     */
    public function existsForCategory(string $categoryId): bool;

    public function remove(Transaction $transaction): void;
}
