<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Item\Exception\ItemNotFound;
use App\Domain\Item\Item;
use App\Domain\Item\ItemId;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Concrete repository (no interface) as a conscious V1 decision.
 *
 * @extends ServiceEntityRepository<Item>
 */
final class DoctrineItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Item::class);
    }

    public function save(Item $item): void
    {
        $this->getEntityManager()->persist($item);
        $this->getEntityManager()->flush();
    }

    public function get(ItemId $id): Item
    {
        $item = $this->find($id);

        if (! $item instanceof Item) {
            throw ItemNotFound::withId($id);
        }

        return $item;
    }

    /**
     * @return Item[]
     */
    public function allWithLoans(): array
    {
        return $this->createQueryBuilder('item')
            ->leftJoin('item.loans', 'loan')
            ->addSelect('loan')
            ->orderBy('item.title', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
