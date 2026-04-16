<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetBadgeCatalog;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BadgesOverviewController extends AbstractController
{
    public function __construct(
        readonly private GetBadgeCatalog $getBadgeCatalog,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odznaky',
            'en' => '/en/badges',
            'es' => '/es/insignias',
            'ja' => '/ja/バッジ',
            'fr' => '/fr/badges',
            'de' => '/de/abzeichen',
        ],
        name: 'badges_overview',
    )]
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $playerId = $profile?->playerId;

        $catalog = $this->getBadgeCatalog->forPlayer($playerId);

        return $this->render('badges_overview.html.twig', [
            'catalog' => $catalog,
            'logged_in' => $playerId !== null,
        ]);
    }
}
