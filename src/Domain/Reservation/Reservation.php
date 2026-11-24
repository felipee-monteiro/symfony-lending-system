<?php

declare(strict_types=1);

namespace App\Domain\Reservation;

use App\Domain\Item\BorrowerId;
use App\Domain\Item\ItemId;
use App\Repository\DoctrineReservationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A reservation is a queue entry for an item that is currently lent. It is a separate
 * aggregate: the queue is queried on its own and "my reservations" crosses items.
 *
 * Uniqueness per (item, borrower) and FIFO ordering are cross-aggregate rules: they are
 * enforced by ReservationPolicy and by the database (unique partial index).
 *
 * Doctrine attributes on the domain class are the same conscious debt as in Item. The
 * private constructor is fine: Doctrine hydrates through reflection, never through it.
 */
#[ORM\Entity(repositoryClass: DoctrineReservationRepository::class)]
#[ORM\Table(name: 'reservation')]
#[ORM\Index(name: 'idx_reservation_fifo', columns: ['item_id', 'reserved_at', 'id'])]
#[ORM\UniqueConstraint(
    name: 'uniq_reservation_active',
    columns: ['item_id', 'borrower_id'],
    options: ['where' => "status IN ('pending', 'notified')"],
)]
final class Reservation
{
    #[ORM\Id]
    #[ORM\Column(type: 'reservation_id')]
    private ReservationId $id;

    #[ORM\Column(type: 'item_id')]
    private ItemId $itemId;

    #[ORM\Column(type: 'borrower_id')]
    private BorrowerId $borrowerId;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $reservedAt;

    #[ORM\Column(length: 20, enumType: ReservationStatus::class)]
    private ReservationStatus $status;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $notifiedAt = null;

    private function __construct(
        ReservationId $id,
        ItemId $itemId,
        BorrowerId $borrowerId,
        DateTimeImmutable $reservedAt,
        ReservationStatus $status,
        ?DateTimeImmutable $notifiedAt,
    ) {
        $this->id         = $id;
        $this->itemId     = $itemId;
        $this->borrowerId = $borrowerId;
        $this->reservedAt = $reservedAt;
        $this->status     = $status;
        $this->notifiedAt = $notifiedAt;
    }

    public static function place(
        ReservationId $id,
        ItemId $itemId,
        BorrowerId $borrowerId,
        DateTimeImmutable $reservedAt,
    ): self {
        return new self($id, $itemId, $borrowerId, $reservedAt, ReservationStatus::Pending, null);
    }

    public function markNotified(DateTimeImmutable $now): void
    {
        if ($this->status !== ReservationStatus::Pending) {
            throw new \DomainException('Only a pending reservation can be notified.');
        }

        $this->status     = ReservationStatus::Notified;
        $this->notifiedAt = $now;
    }

    public function cancel(): void
    {
        if ($this->status === ReservationStatus::Cancelled) {
            throw new \DomainException('Reservation is already cancelled.');
        }

        $this->status = ReservationStatus::Cancelled;
    }

    public function id(): ReservationId
    {
        return $this->id;
    }

    public function itemId(): ItemId
    {
        return $this->itemId;
    }

    public function borrowerId(): BorrowerId
    {
        return $this->borrowerId;
    }

    public function reservedAt(): DateTimeImmutable
    {
        return $this->reservedAt;
    }

    public function status(): ReservationStatus
    {
        return $this->status;
    }

    public function notifiedAt(): ?DateTimeImmutable
    {
        return $this->notifiedAt;
    }
}
