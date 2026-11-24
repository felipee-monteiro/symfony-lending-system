<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Reservation;

use App\Domain\Item\BorrowerId;
use App\Domain\Item\ItemId;
use App\Domain\Reservation\Reservation;
use App\Domain\Reservation\ReservationId;
use App\Domain\Reservation\ReservationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReservationTest extends TestCase
{
    #[Test]
    public function a_new_reservation_is_pending_and_keeps_the_reserved_at(): void
    {
        // Arrange
        $reservedAt = new \DateTimeImmutable('2026-02-01 10:00:00');

        // Act
        $reservation = $this->reservation($reservedAt);

        // Assert
        self::assertSame(ReservationStatus::Pending, $reservation->status());
        self::assertSame($reservedAt, $reservation->reservedAt());
        self::assertNull($reservation->notifiedAt());
    }

    #[Test]
    public function a_pending_reservation_can_be_notified(): void
    {
        // Arrange
        $reservation = $this->reservation(new \DateTimeImmutable('2026-02-01 10:00:00'));
        $notifiedAt  = new \DateTimeImmutable('2026-02-05 09:30:00');

        // Act
        $reservation->markNotified($notifiedAt);

        // Assert
        self::assertSame(ReservationStatus::Notified, $reservation->status());
        self::assertSame($notifiedAt, $reservation->notifiedAt());
    }

    #[Test]
    public function an_already_notified_reservation_cannot_be_notified_again(): void
    {
        // Arrange
        $reservation = $this->reservation(new \DateTimeImmutable('2026-02-01 10:00:00'));
        $notifiedAt  = new \DateTimeImmutable('2026-02-05 09:30:00');
        $reservation->markNotified($notifiedAt);

        // Act & Assert
        try {
            $reservation->markNotified(new \DateTimeImmutable('2026-02-06 09:30:00'));
            self::fail('A notified reservation must not be notified again.');
        } catch (\DomainException $exception) {
            self::assertSame('Only a pending reservation can be notified.', $exception->getMessage());
        }

        self::assertSame(ReservationStatus::Notified, $reservation->status());
        self::assertSame($notifiedAt, $reservation->notifiedAt(), 'The original notifiedAt must be kept.');
    }

    #[Test]
    public function a_cancelled_reservation_cannot_be_notified(): void
    {
        // Arrange
        $reservation = $this->reservation(new \DateTimeImmutable('2026-02-01 10:00:00'));
        $reservation->cancel();

        // Act & Assert
        try {
            $reservation->markNotified(new \DateTimeImmutable('2026-02-05 09:30:00'));
            self::fail('A cancelled reservation must not be notified.');
        } catch (\DomainException $exception) {
            self::assertSame('Only a pending reservation can be notified.', $exception->getMessage());
        }

        self::assertSame(ReservationStatus::Cancelled, $reservation->status());
        self::assertNull($reservation->notifiedAt());
    }

    #[Test]
    public function a_pending_reservation_can_be_cancelled(): void
    {
        // Arrange
        $reservedAt  = new \DateTimeImmutable('2026-02-01 10:00:00');
        $reservation = $this->reservation($reservedAt);

        // Act
        $reservation->cancel();

        // Assert
        self::assertSame(ReservationStatus::Cancelled, $reservation->status());
        self::assertSame($reservedAt, $reservation->reservedAt(), 'Cancelling must not rewrite reservedAt.');
    }

    #[Test]
    public function a_notified_reservation_can_be_cancelled(): void
    {
        // Arrange
        $reservation = $this->reservation(new \DateTimeImmutable('2026-02-01 10:00:00'));
        $notifiedAt  = new \DateTimeImmutable('2026-02-05 09:30:00');
        $reservation->markNotified($notifiedAt);

        // Act
        $reservation->cancel();

        // Assert
        self::assertSame(ReservationStatus::Cancelled, $reservation->status());
        self::assertSame($notifiedAt, $reservation->notifiedAt(), 'Cancelling must not erase notifiedAt.');
    }

    #[Test]
    public function a_cancelled_reservation_cannot_be_cancelled_again(): void
    {
        // Arrange
        $reservation = $this->reservation(new \DateTimeImmutable('2026-02-01 10:00:00'));
        $reservation->cancel();

        // Act & Assert
        try {
            $reservation->cancel();
            self::fail('A cancelled reservation must not be cancelled again.');
        } catch (\DomainException $exception) {
            self::assertSame('Reservation is already cancelled.', $exception->getMessage());
        }

        self::assertSame(ReservationStatus::Cancelled, $reservation->status());
    }

    private function reservation(\DateTimeImmutable $reservedAt): Reservation
    {
        return Reservation::place(
            ReservationId::fromString('reservation-1'),
            ItemId::fromString('item-1'),
            BorrowerId::fromString('borrower-1'),
            $reservedAt,
        );
    }
}
