<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\LevelTable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class XpExplainerController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/jak-funguji-xp',
            'en' => '/en/how-xp-works',
            'es' => '/es/como-funciona-xp',
            'ja' => '/ja/xpの仕組み',
            'fr' => '/fr/comment-fonctionne-xp',
            'de' => '/de/wie-xp-funktioniert',
        ],
        name: 'xp_explainer',
    )]
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        $curve = [];

        for ($level = 1; $level <= LevelTable::MAX_LEVEL; $level++) {
            $curve[$level] = LevelTable::xpForLevel($level);
        }

        return $this->render('xp_explainer.html.twig', [
            'levels' => $curve,
        ]);
    }
}
