<?php

declare(strict_types=1);

namespace App\EventListener\Exceptions;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class InvalidArgumentExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 20],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exceptionSubscriber = new ResponseStrategy($event);

        $exception = $event->getThrowable();

        if (! $exception instanceof \InvalidArgumentException) {
            return;
        }

        $exceptionSubscriber->configure();

        $event->stopPropagation();
    }
}
