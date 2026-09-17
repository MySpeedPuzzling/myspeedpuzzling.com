<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use LogicException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Fails any test whose form POST is answered with a plain 200 page. In a browser Turbo Drive discards that
 * answer - no page, no flash, no field error - so it is a bug the visitor sees as "nothing happened".
 * TurboDriveFormResponseSubscriber reports it from production, but only for real Turbo requests; the test
 * client sends no Turbo headers, so this guard holds every form submission in the suite to the same rule.
 *
 * Redirect on success; render a re-shown form with 422 ('form' => $form, not $form->createView()).
 */
final readonly class SilentFormSubmissionGuard implements EventSubscriberInterface
{
    /**
     * Routes whose forms opt out of Turbo (data-turbo="false"), so the browser renders a 200 itself.
     *
     * @var array<string, string> route => why
     */
    private const array NATIVE_FORM_ROUTES = [
        'confirm_account_deletion' => 'data-turbo="false": the deletion POST must be a full, native navigation',
    ];

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -2048],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || $request->isMethodSafe()) {
            return;
        }

        // Frame submissions (e.g. the modal-frame) and Live Component actions render a 200 legitimately
        if ($request->headers->has('Turbo-Frame') || $request->attributes->has('_live_component')) {
            return;
        }

        // Only what a <form> sends - JSON APIs and webhooks answer 200 by design
        $requestFormat = (string) $request->headers->get('Content-Type');
        if (!str_starts_with($requestFormat, 'application/x-www-form-urlencoded') && !str_starts_with($requestFormat, 'multipart/form-data')) {
            return;
        }

        $response = $event->getResponse();

        if ($response->getStatusCode() !== Response::HTTP_OK || !str_starts_with((string) $response->headers->get('Content-Type'), 'text/html')) {
            return;
        }

        $route = $request->attributes->get('_route');

        if (is_string($route) && array_key_exists($route, self::NATIVE_FORM_ROUTES)) {
            return;
        }

        throw new LogicException(sprintf(
            'Form POST to "%s" (route "%s") was answered with a 200 page, which Turbo Drive discards in the browser - the visitor sees nothing happen. Redirect on success, or render the form again with 422 (pass the FormInterface, not createView()). If the form really opts out of Turbo, add the route to %s::NATIVE_FORM_ROUTES.',
            $request->getPathInfo(),
            is_string($route) ? $route : '?',
            self::class,
        ));
    }
}
