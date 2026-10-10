<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * How karma works (docs/features/karma.md) - the "For everyone" deep page of the "How it works" hub. Public; a signed-in
 * player sees their own karma from their profile row (no query of its own). Like every progression page it opens with
 * the XP launch switch (XpFeatureGate - admins before).
 */
final class KarmaExplainerController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/jak-funguje-karma',
            'en' => '/en/how-karma-works',
            'es' => '/es/como-funciona-karma',
            'ja' => '/ja/カルマの仕組み',
            'fr' => '/fr/comment-fonctionne-karma',
            'de' => '/de/wie-karma-funktioniert',
        ],
        name: 'karma_explainer',
    )]
    public function __invoke(): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($viewer) === false) {
            throw $this->createNotFoundException();
        }

        return $this->render('karma/explainer.html.twig', [
            'viewer' => $viewer,
        ]);
    }
}
