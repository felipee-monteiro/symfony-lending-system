<?php

declare(strict_types=1);

namespace App\Domain\Item;

use App\Domain\Item\Event\ItemReturned;
use App\Repository\DoctrineItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Aggregate root. Doctrine attributes on the domain class are a conscious V1 debt.
 */
#[ORM\Entity(repositoryClass: DoctrineItemRepository::class)]
#[ORM\Table(name: 'item')]
final class Item
{
    #[ORM\Id]
    #[ORM\Column(type: 'item_id')]
    private ItemId $id;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 20, enumType: ItemStatus::class)]
    private ItemStatus $status;

    /** @var Collection<int, Loan> */
    #[ORM\OneToMany(mappedBy: 'item', targetEntity: Loan::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $loans;

    /** @var list<object> Domain facts recorded by this aggregate, released by the application layer. */
    private array $domainEvents = [];

    public function __construct(ItemId $id, string $title)
    {
        $this->id     = $id;
        $this->title  = $title;
        $this->status = ItemStatus::Available;
        $this->loans  = new ArrayCollection;
    }

    public function lendTo(BorrowerId $borrower, \DateTimeImmutable $lentAt, \DateTimeImmutable $dueAt): void
    {
        if ($this->status === ItemStatus::Lent) {
            throw new \DomainException('Item is already lent.');
        }

        $this->loans->add(new Loan($this, $borrower, Period::startedAt($lentAt, $dueAt)));
        $this->status = ItemStatus::Lent;
    }

    public function returnFrom(\DateTimeImmutable $at): void
    {
        if ($this->status === ItemStatus::Available) {
            throw new \DomainException('Item is not lent.');
        }

        $this->openLoan()->markReturned($at);
        $this->status = ItemStatus::Available;

        $this->domainEvents[] = new ItemReturned($this->id, $at);
    }

    /** @return list<object> */
    public function releaseEvents(): array
    {
        $events             = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    public function id(): ItemId
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function status(): ItemStatus
    {
        return $this->status;
    }

    /** @return Loan[] */
    public function loans(): array
    {
        return $this->loans->toArray();
    }

    public function currentLoan(): ?Loan
    {
        if ($this->status !== ItemStatus::Lent) {
            return null;
        }

        return $this->openLoan();
    }

    public function renewLoan(\DateTimeImmutable $newDueAt, \DateTimeImmutable $renewedAt): void
    {
        if ($this->status !== ItemStatus::Lent) {
            throw new \DomainException('Item não está emprestado');
        }

        $this->openLoan()->renew($newDueAt, $renewedAt);
    }

    private function openLoan(): Loan
    {
        foreach (array_reverse($this->loans->toArray()) as $loan) {
            if ($loan->isOpen()) {
                return $loan;
            }
        }

        throw new \LogicException('A lent item must have an open loan.');
    }
}
