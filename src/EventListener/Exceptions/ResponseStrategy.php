<?php

declare(strict_types=1);

namespace App\EventListener\Exceptions;

use LogicException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Throwable;

final readonly class ResponseStrategy
{
    private Throwable $exception;

    private Request $request;

    public function __construct(
        private readonly ExceptionEvent $event
    ) {
        $this->exception = $event->getThrowable();
        $this->request   = $event->getRequest();
    }

    public function configure(): void
    {
        $this->configureApiResponse();
        $this->configureBagResponse();
    }

    private function configureApiResponse(): void
    {
        if ($this->request->getPreferredFormat() === 'json') {
            $this->event->setResponse(
                new JsonResponse(['error' => $this->exception->getMessage()], 409)
            );
        }
    }

    private function configureBagResponse(): void
    {
        if ($this->request->getPreferredFormat() === 'html') {
            if ($this->request->hasSession()) {
                $this->request->getSession()->getFlashBag()->add('error', $this->exception->getMessage());

                $referer = $this->request->headers->get('referer');

                if ($referer === null) {
                    throw new LogicException(\sprintf('You MUST set the referer header'));
                }

                $this->event->setResponse(new RedirectResponse($referer));
            }
        }
    }
}
