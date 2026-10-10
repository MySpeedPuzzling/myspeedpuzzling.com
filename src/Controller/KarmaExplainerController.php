<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder for the karma deep page of "How progress works" - workstream F (karma) replaces this controller and its
 * template with the real page under the same route name.
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
            'es' => '/es/como-funciona-el-karma',
            'ja' => '/ja/カルマの仕組み',
            'fr' => '/fr/comment-fonctionne-le-karma',
            'de' => '/de/so-funktioniert-karma',
        ],
        name: 'karma_explainer',
    )]
    public function __invoke(): Response
    {
        if ($this->xpFeatureGate->isVisibleFor($this->retrieveLoggedUserProfile->getProfile()) === false) {
            throw $this->createNotFoundException();
        }

        return $this->render('karma_explainer.html.twig');
    }
}
