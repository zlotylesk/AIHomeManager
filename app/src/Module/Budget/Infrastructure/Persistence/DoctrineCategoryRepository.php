<?php

declare(strict_types=1);

namespace App\Module\Budget\Infrastructure\Persistence;

use App\Module\Budget\Domain\Entity\Category;
use App\Module\Budget\Domain\Repository\CategoryRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Category $category): void
    {
        $this->entityManager->persist($category);
        $this->entityManager->flush();
    }

    public function findById(string $id): ?Category
    {
        return $this->entityManager->find(Category::class, $id);
    }

    /** @return Category[] */
    public function findAll(): array
    {
        return $this->entityManager->createQuery('SELECT c FROM '.Category::class.' c')->getResult();
    }

    public function remove(Category $category): void
    {
        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }
}
