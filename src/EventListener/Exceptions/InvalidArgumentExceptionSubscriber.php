<?php

declare(strict_types=1);

namespace App\EventListener\Exceptions;

use InvalidArgumentException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Invalid input (an empty identifier, for example) is a 422 Unprocessable Entity.
 */
final readonly class InvalidArgumentExceptionSubscriber implements EventSubscriberInterface
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
        if (! $event->getThrowable() instanceof InvalidArgumentException) {
            return;
        }

        (new ResponseStrategy($event, $this->urls, 422))->configure();
    }
}
