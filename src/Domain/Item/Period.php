<?php

declare(strict_types=1);

namespace App\Domain\Item;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mapped as an embeddable inside Loan (columns lent_at/due_at/returned_at on the loan table).
 * Not readonly on purpose: Doctrine hydrates the properties directly, bypassing the
 * constructor, so the period invariants rely on the domain behavior.
 */
#[ORM\Embeddable]
final class Period
{
    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $lentAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $dueAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $returnedAt;

    private function __construct(
        DateTimeImmutable $lentAt,
        DateTimeImmutable $dueAt,
        ?DateTimeImmutable $returnedAt,
    ) {
        if ($dueAt < $lentAt) {
            throw new \InvalidArgumentException('A period cannot be due before it starts.');
        }

        if ($returnedAt !== null && $returnedAt < $lentAt) {
            throw new \InvalidArgumentException('A period cannot end before it starts.');
        }

        $this->lentAt     = $lentAt;
        $this->dueAt      = $dueAt;
        $this->returnedAt = $returnedAt;
    }

    public static function startedAt(DateTimeImmutable $lentAt, DateTimeImmutable $dueAt): self
    {
        return new self($lentAt, $dueAt, null);
    }

    public function endedAt(DateTimeImmutable $returnedAt): self
    {
        return new self($this->lentAt, $this->dueAt, $returnedAt);
    }

    public function lentAt(): DateTimeImmutable
    {
        return $this->lentAt;
    }

    public function dueAt(): DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function returnedAt(): ?DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function isOpen(): bool
    {
        return $this->returnedAt === null;
    }

    public function isClosed(): bool
    {
        return $this->returnedAt !== null;
    }
}
