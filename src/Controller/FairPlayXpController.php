<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FairPlayXpController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/fair-play-xp',
            'en' => '/en/fair-play-xp',
            'es' => '/es/fair-play-xp',
            'ja' => '/ja/フェアプレイxp',
            'fr' => '/fr/fair-play-xp',
            'de' => '/de/fair-play-xp',
        ],
        name: 'xp_fair_play',
    )]
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        return $this->render('xp_fair_play.html.twig');
    }
}
