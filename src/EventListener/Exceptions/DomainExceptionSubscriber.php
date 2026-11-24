<?php

declare(strict_types=1);

namespace App\EventListener\Exceptions;

use DomainException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class DomainExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 20],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $responseStrategy = new ResponseStrategy($event);
        $exception        = $event->getThrowable();

        if (! $exception instanceof DomainException) {
            return;
        }

        $responseStrategy->configure();

        $event->stopPropagation();
    }
}
