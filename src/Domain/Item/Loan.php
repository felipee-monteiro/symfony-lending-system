<?php

declare(strict_types=1);

namespace App\Domain\Item;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Entity internal to the Item aggregate. It is never created or loaded on its own,
 * and it is reached only through Item. Doctrine attributes live here as a conscious
 * V1 debt (no XML mapping).
 */
#[ORM\Entity]
#[ORM\Table(name: 'loan')]
final class Loan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'loans')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Item $item;

    #[ORM\Column(type: 'borrower_id')]
    private BorrowerId $borrowerId;

    #[ORM\Embedded(class: Period::class, columnPrefix: false)]
    private Period $period;

    /** @var Collection<int, LoanRenewal> */
    #[ORM\OneToMany(mappedBy: 'loan', targetEntity: LoanRenewal::class, cascade: ['persist'], orphanRemoval: false)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $renewals;

    public function __construct(Item $item, BorrowerId $borrowerId, Period $period)
    {
        $this->item       = $item;
        $this->borrowerId = $borrowerId;
        $this->period     = $period;
        $this->renewals   = new ArrayCollection;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function item(): Item
    {
        return $this->item;
    }

    public function borrowerId(): BorrowerId
    {
        return $this->borrowerId;
    }

    public function period(): Period
    {
        return $this->period;
    }

    public function isOpen(): bool
    {
        return $this->period->isOpen();
    }

    public function markReturned(DateTimeImmutable $at): void
    {
        $this->period = $this->period->endedAt($at);
    }

    public function renew(DateTimeImmutable $newDueAt, DateTimeImmutable $renewedAt): LoanRenewal
    {
        if (! $this->isOpen()) {
            throw new \DomainException('A returned loan cannot be renewed.');
        }

        if ($newDueAt <= $this->effectiveDueAt()) {
            throw new \DomainException('A renovação deve estender o prazo');
        }

        $renewal = new LoanRenewal($this, $newDueAt, $renewedAt);
        $this->renewals->add($renewal);

        return $renewal;
    }

    /**
     * The period keeps the original due date; renewals only push the effective one.
     */
    public function effectiveDueAt(): DateTimeImmutable
    {
        if ($this->renewals->isEmpty()) {
            return $this->period->dueAt();
        }

        /** @var LoanRenewal $latest */
        $latest = $this->renewals->last();

        return $latest->dueAt();
    }

    /**
     * @return LoanRenewal[]
     */
    public function renewals(): array
    {
        return $this->renewals->toArray();
    }

    /**
     * A returned loan is measured against its return date, not against "now".
     */
    public function daysLateAt(DateTimeImmutable $now): int
    {
        return OverdueDays::calculate($this->effectiveDueAt(), $this->period->returnedAt() ?? $now);
    }
}
