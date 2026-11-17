<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A competition display - a timer screen in the hall or a stream overlay in OBS (D-displays-overlays.md §7.1,
 * competition toolkit D4). The token in the path is the access right: no locale (the URL never changes), no session,
 * no cookie (the `stateless` firewall), never cached, never indexed, no Referer to the picture hosts.
 *
 * A standalone page - not base.html.twig: no site chrome, no service worker registration, no self-heal scripts, no
 * Turbo. The first state and the design are inline; assets/display.js draws them and keeps them live.
 *
 * An unknown or regenerated token gets its own tiny standalone page with a 404 instead of the site's error page (with
 * the header, footer and service worker of the site) - that is why CompetitionDisplayNotFound is caught here. Its kind
 * is unknown then, so the page is transparent with one short line: whoever pastes a dead link into OBS sees why.
 */
final class CompetitionDisplayController extends AbstractController
{
    public function __construct(
        private readonly CompetitionDisplayPayload $payload,
    ) {
    }

    #[Route(path: '/display/{token}', name: 'competition_display', methods: ['GET'])]
    public function __invoke(string $token): Response
    {
        try {
            $page = $this->payload->pageForToken($token);
            $response = $this->render('competition_display/display.html.twig', [
                'page' => $page,
                'token' => $token,
            ]);
        } catch (CompetitionDisplayNotFound) {
            $response = $this->render('competition_display/not_found.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
