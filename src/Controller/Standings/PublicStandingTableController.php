<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Standings;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionStandingNotFound;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Services\Standings\PublicStandingSnapshots;
use SpeedPuzzling\Web\Services\Standings\StandingPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The table part of a public standing page as a fragment (competition toolkit v2 S4, displays-standings.md §4.2) - what
 * the open page (assets/standing_live.js) swaps in after the event's public standings signal, or on its 60 s poll while
 * no stream is open. Main firewall: the signed-in viewer gets the page's own rules (blocklist, allow list, "you").
 *
 * - 404 like the page (StandingPageBuilder::buildById()): an unknown or unpublished standing, an event that is not public
 *   (a draft - for its team too). The event's visibility is read on every request (it is not signalled).
 * - Guests: the HTML is rendered once per generation of the event's snapshots and locale (PublicStandingSnapshots::
 *   guestHtml()) - warm 1 statement (the visibility), so a signal answered by hundreds of open pages within seconds costs
 *   one render. Signed in: rendered for the viewer every time.
 * - `Cache-Control: private, no-cache`, never indexed.
 */
final class PublicStandingTableController extends AbstractController
{
    public function __construct(
        private readonly StandingPageBuilder $standingPageBuilder,
        private readonly PublicStandingSnapshots $snapshots,
        private readonly IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
    ) {
    }

    #[Route(
        path: '/{_locale}/standing-table/{standingId}',
        name: 'public_standing_table',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'standingId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $standingId): Response
    {
        $competitionId = $this->snapshots->competitionOf($standingId);

        if ($competitionId === null || $this->isCompetitionPubliclyVisible->check($competitionId) === false) {
            throw new CompetitionStandingNotFound();
        }

        $render = fn (): string => $this->renderView('standing/table_fragment.html.twig', [
            'view' => $this->standingPageBuilder->buildById($standingId),
        ]);

        $html = $this->getUser() === null
            ? $this->snapshots->guestHtml($standingId, $competitionId, $request->getLocale(), $render)
            : $render();

        $response = new Response($html);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
