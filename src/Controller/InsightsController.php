<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\Insights\InsightsFeature;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The player's own Insights page (docs/features/insights/page.md): always the signed-in player's own data - the URL
 * carries no player, so nobody can open anybody else's. Everything it shows is the Insights live component's; the URL
 * is its filter state. A 404 while InsightsFeature hides it (flag INSIGHTS_PUBLIC off, not an admin). Personal: never
 * indexed, never shared-cached.
 */
final class InsightsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private InsightsFeature $insightsFeature,
        readonly private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    #[Route(
        path: '/{_locale}/insights',
        name: 'insights',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('my_profile');
        }

        if ($this->insightsFeature->isVisibleTo($profile) === false) {
            throw new NotFoundHttpException();
        }

        $response = $this->render('insights.html.twig', [
            // The subject's row (byId()), not the viewer's: the header's tier and MSP Rating chips ride on it
            'player' => $this->getPlayerProfile->byId($profile->playerId),
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
