<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LiveResults;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The live result entry page's offline copy - the "shell" (competition toolkit C3,
 * docs/features/competitions-management/toolkit/C-judge-page.md §1, R §2.7): the very markup of `live_results`, one per
 * locale, rendered with nothing of any round, event or person. The service worker stores it on a referee's device
 * (`live-shell:prepare`, C6) and answers it only when the network fails for a live entry URL; the page then fills
 * itself from the device's own saved rounds of the player the cookie `msp_live_player` names (C4/C5).
 *
 * Answered the same for everybody, so it may sit in any cache: no session is read or started (the path is on the
 * `stateless` firewall - no user, no remember-me renewal, no cookie at all), no `msp-rendered-at` timestamp (kept for
 * hours on purpose, the stale-document check must not judge it), never the native apps' Turbo (they run no worker).
 * Not indexed.
 */
final class LiveResultsShellController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/zadavani-vysledku-shell',
            'en' => '/en/live-results-shell',
            'es' => '/es/live-results-shell',
            'ja' => '/ja/live-results-shell',
            'fr' => '/fr/live-results-shell',
            'de' => '/de/live-results-shell',
        ],
        name: 'live_results_shell',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        $response = $this->render('live_results/live_results.html.twig', [
            'live_entry_shell' => true,
            'round_id' => '',
            'entrant_id' => null,
            'table' => null,
            'entry' => null,
            'auto_picked' => false,
            'unknown_tag' => false,
        ]);

        $response->setPublic();
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
