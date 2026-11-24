<?php

declare(strict_types=1);

namespace App\EventListener\Exceptions;

use DomainException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A domain exception means the requested state transition conflicts with the current
 * state (lent item, duplicate queue entry, cancellation of a cancelled reservation), so
 * the API status is 409 Conflict.
 */
final readonly class DomainExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 20],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (! $event->getThrowable() instanceof DomainException) {
            return;
        }

        (new ResponseStrategy($event, $this->urls, 409))->configure();
    }
}
