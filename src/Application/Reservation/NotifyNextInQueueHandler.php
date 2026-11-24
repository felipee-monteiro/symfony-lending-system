<?php

declare(strict_types=1);

namespace App\Application\Reservation;

use App\Repository\DoctrineReservationRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Delivers the notification for the head of the queue. Delivery is at-least-once, so the
 * handler must be idempotent: a redelivered message for an already handled return does
 * nothing.
 */
#[AsMessageHandler]
final readonly class NotifyNextInQueueHandler
{
    public function __construct(
        private DoctrineReservationRepository $reservations,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {}

    public function __invoke(NotifyNextInQueue $message): void
    {
        if ($this->reservations->hasNotifiedSince($message->itemId, $message->returnedAt)) {
            return;
        }

        $reservation = $this->reservations->nextPendingFor($message->itemId, $message->returnedAt);

        if ($reservation === null) {
            $this->logger->info('Fila vazia para o item {item}.', ['item' => $message->itemId->toString()]);

            return;
        }

        $reservation->markNotified($this->clock->now());
        $this->reservations->save($reservation);

        $this->logger->info('Borrower {borrower} notificado para o item {item}.', [
            'borrower' => $reservation->borrowerId()->toString(),
            'item'     => $message->itemId->toString(),
        ]);
    }
}
