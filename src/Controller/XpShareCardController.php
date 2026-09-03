<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Services\GetXpShareCard;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Share-card image route — direct URL access counts as a leak surface, so the
 * feature gate guards it like every page. Opted-out players never get cards.
 */
final class XpShareCardController extends AbstractController
{
    public function __construct(
        readonly private GetXpShareCard $getXpShareCard,
        readonly private GetXpProfile $getXpProfile,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: '/xp-card/{playerId}/{variant}',
        name: 'xp_share_card',
        requirements: ['variant' => 'launch|level-up'],
    )]
    public function __invoke(string $playerId, string $variant): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($viewer) === false) {
            throw $this->createNotFoundException();
        }

        if ($this->getXpProfile->byPlayerId($playerId)->optedOut) {
            throw $this->createNotFoundException();
        }

        $fileContent = $this->getXpShareCard->forPlayer($playerId, $variant);

        return new Response($fileContent, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline',
        ]);
    }
}
