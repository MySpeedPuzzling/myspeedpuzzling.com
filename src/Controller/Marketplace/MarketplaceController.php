<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Marketplace;

use SpeedPuzzling\Web\Query\IsHintDismissed;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MarketplaceController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private IsHintDismissed $isHintDismissed,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/marketplace',
            'en' => '/en/marketplace',
            'es' => '/es/marketplace',
            'ja' => '/ja/marketplace',
            'fr' => '/fr/marketplace',
            'de' => '/de/marketplace',
        ],
        name: 'marketplace',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        $disclaimerDismissed = false;

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        if ($loggedPlayer !== null) {
            $disclaimerDismissed = ($this->isHintDismissed)($loggedPlayer->playerId, HintType::MarketplaceDisclaimer);
        }

        return $this->render('marketplace/index.html.twig', [
            'disclaimer_dismissed' => $disclaimerDismissed,
        ]);
    }
}
