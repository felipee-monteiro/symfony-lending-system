<?php

declare(strict_types=1);

namespace App\EventListener\Exceptions;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns an exception into an HTTP response without leaking it to the kernel: JSON for
 * API clients, a flash message plus redirect for the server-rendered screens.
 *
 * The HTTP status is chosen by the subscriber that owns the exception family (state
 * conflicts are 409, invalid input is 422).
 */
final readonly class ResponseStrategy
{
    public function __construct(
        private ExceptionEvent $event,
        private UrlGeneratorInterface $urls,
        private int $apiStatus,
    ) {}

    public function configure(): void
    {
        $request = $this->event->getRequest();
        $message = $this->event->getThrowable()->getMessage();

        if (self::isApiRequest($request)) {
            $this->event->setResponse(new JsonResponse(['error' => $message], $this->apiStatus));

            return;
        }

        if (! $request->hasSession()) {
            return;
        }

        $request->getSession()->getFlashBag()->add('error', $message);

        $this->event->setResponse(new RedirectResponse($this->redirectTarget($request)));
    }

    private static function isApiRequest(Request $request): bool
    {
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            return true;
        }

        return $request->getPreferredFormat() === 'json'
            || str_contains((string) $request->headers->get('Accept'), 'application/json');
    }

    private function redirectTarget(Request $request): string
    {
        $referer = $request->headers->get('referer');

        // Never bounce to another host: a forged Referer would be an open redirect.
        if ($referer !== null && $referer !== '') {
            $host = parse_url($referer, \PHP_URL_HOST);

            if ($host === null || $host === $request->getHost()) {
                return $referer;
            }
        }

        return $this->urls->generate('app_items_index');
    }
}
