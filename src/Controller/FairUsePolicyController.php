<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FairUsePolicyController extends AbstractController
{
    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zasady-fer-pouzivani',
            'en' => '/en/fair-use-policy',
            'es' => '/es/politica-de-uso-justo',
            'ja' => '/ja/フェアユースポリシー',
            'fr' => '/fr/politique-utilisation-equitable',
            'de' => '/de/fair-use-richtlinie',
        ],
        name: 'fair_use_policy',
    )]
    public function __invoke(): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        return $this->render('fair-use-policy.html.twig', [
            'fair_use_policy_accepted' => $player !== null && $player->fairUsePolicyAccepted,
        ]);
    }
}
