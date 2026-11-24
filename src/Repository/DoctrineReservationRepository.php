<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Item\BorrowerId;
use App\Domain\Item\ItemId;
use App\Domain\Reservation\Reservation;
use App\Domain\Reservation\ReservationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Concrete repository (no interface) as a conscious V1 decision.
 *
 * @extends ServiceEntityRepository<Reservation>
 */
final class DoctrineReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    public function save(Reservation $reservation): void
    {
        $this->getEntityManager()->persist($reservation);
        $this->getEntityManager()->flush();
    }

    public function hasActiveFor(ItemId $itemId, BorrowerId $borrowerId): bool
    {
        $count = $this->createQueryBuilder('reservation')
            ->select('COUNT(reservation.id)')
            ->where('reservation.itemId = :itemId')
            ->andWhere('reservation.borrowerId = :borrowerId')
            ->andWhere('reservation.status IN (:activeStatuses)')
            ->setParameter('itemId', $itemId, 'item_id')
            ->setParameter('borrowerId', $borrowerId, 'borrower_id')
            ->setParameter('activeStatuses', [
                ReservationStatus::Pending->value,
                ReservationStatus::Notified->value,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function nextPendingFor(ItemId $itemId, \DateTimeImmutable $before): ?Reservation
    {
        return $this->createQueryBuilder('reservation')
            ->where('reservation.itemId = :itemId')
            ->andWhere('reservation.status = :status')
            ->andWhere('reservation.reservedAt <= :before')
            ->setParameter('itemId', $itemId, 'item_id')
            ->setParameter('status', ReservationStatus::Pending->value)
            ->setParameter('before', $before)
            ->orderBy('reservation.reservedAt', 'ASC')
            ->addOrderBy('reservation.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasNotifiedSince(ItemId $itemId, \DateTimeImmutable $since): bool
    {
        $count = $this->createQueryBuilder('reservation')
            ->select('COUNT(reservation.id)')
            ->where('reservation.itemId = :itemId')
            ->andWhere('reservation.notifiedAt >= :since')
            ->setParameter('itemId', $itemId, 'item_id')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
