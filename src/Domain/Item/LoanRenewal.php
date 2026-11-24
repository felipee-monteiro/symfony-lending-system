<?php

declare(strict_types=1);

namespace App\Domain\Item;

use Doctrine\ORM\Mapping as ORM;

/**
 * A renewal is an append-only record: it never overwrites the loan period, it just
 * pushes the effective due date forward. Doctrine attributes live here as a conscious
 * V1/V2 debt (no XML mapping).
 */
#[ORM\Entity]
#[ORM\Table(name: 'loan_renewal')]
final class LoanRenewal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'renewals')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Loan $loan;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dueAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $renewedAt;

    public function __construct(Loan $loan, \DateTimeImmutable $dueAt, \DateTimeImmutable $renewedAt)
    {
        $this->loan      = $loan;
        $this->dueAt     = $dueAt;
        $this->renewedAt = $renewedAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function loan(): Loan
    {
        return $this->loan;
    }

    public function dueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function renewedAt(): \DateTimeImmutable
    {
        return $this->renewedAt;
    }
}
