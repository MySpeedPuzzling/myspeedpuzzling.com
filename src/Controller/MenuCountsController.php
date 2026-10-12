<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetEventsViewerData;
use SpeedPuzzling\Web\Query\GetPlayerReviewCounts;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The Menu's numbers that are not on the page (docs/features/navigation README §4.12 "Menu data" 3, MENU-01): how many
 * events, series and organizations the viewer organises - the "You organize (n)" rule (EventsViewerData::organizedCount(),
 * asked only when the profile row says they organise anything) - and what waits on Review your results (the banner's
 * count, PlayerReviewCounts::total()). Loaded when the Menu is shown (the `menu` page now; the phone panel and the
 * desktop overlay when they open, the docked column at most every 5 minutes per tab - assets/navigation/menu_counts.js),
 * never with each page. The viewer's own numbers only: private, never stored.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class MenuCountsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetEventsViewerData $getEventsViewerData,
        readonly private GetPlayerReviewCounts $getPlayerReviewCounts,
    ) {
    }

    #[Route(
        path: '/{_locale}/menu-counts.json',
        name: 'menu_counts',
        methods: ['GET'],
    )]
    public function __invoke(): JsonResponse
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            throw new AccessDeniedException();
        }

        $response = new JsonResponse([
            'organised' => $profile->organizesEvents ? $this->getEventsViewerData->forPlayer($profile->playerId)->organizedCount() : null,
            'review' => $this->getPlayerReviewCounts->forPlayer($profile->playerId, withFirstTryConflicts: true)->total(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
