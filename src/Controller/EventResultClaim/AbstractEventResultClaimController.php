<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventResultClaim;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The claim, release and removal of a spot of an imported event result (docs/features/event-results-import/README.md
 * "Claiming a spot", "Gates") are one small confirmation each: in the modal when opened from a page, a page of its own
 * otherwise. GET only shows it; the POST (stateless CSRF `event_result_claim`) dispatches, answers a refusal with 422
 * in the same place and a success by closing the modal and refreshing the page (or a redirect).
 */
abstract class AbstractEventResultClaimController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'event_result_claim';

    /**
     * @param array<string, mixed> $parameters
     */
    protected function renderConfirmation(Request $request, string $title, string $bodyTemplate, array $parameters, int $status = Response::HTTP_OK): Response
    {
        $parameters = [...$parameters, 'title' => $title, 'body_template' => $bodyTemplate];

        $response = $this->render(
            $this->isModalRequest($request) ? 'event_result_claim/modal.html.twig' : 'event_result_claim/page.html.twig',
            $parameters,
            new Response(status: $status),
        );
        $response->setVary('Turbo-Frame', false);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    protected function done(Request $request, string $flash, string $fallbackUrl): Response
    {
        $this->addFlash('success', $flash);

        if ($this->isModalRequest($request)) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            // The page behind the modal shows the result as it is now (the same stream as after deleting a time)
            return $this->render('delete-time_success_stream.html.twig');
        }

        return $this->redirect($fallbackUrl, Response::HTTP_SEE_OTHER);
    }

    protected function isModalRequest(Request $request): bool
    {
        return $request->headers->get('Turbo-Frame') === 'modal-frame';
    }

    protected function isCsrfValid(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'));
    }
}
