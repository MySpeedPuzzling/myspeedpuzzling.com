<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetGettingStartedProgress;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Getting started": what the platform is for, in six short steps (docs/features/getting-started-guide.md).
 * Public and always reachable from the footer; signed in, the steps show what is already done.
 */
final class GettingStartedController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetGettingStartedProgress $getGettingStartedProgress,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/jak-zacit',
            'en' => '/en/getting-started',
            'es' => '/es/primeros-pasos',
            'ja' => '/ja/はじめに',
            'fr' => '/fr/premiers-pas',
            'de' => '/de/erste-schritte',
        ],
        name: 'getting_started',
    )]
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        return $this->render('onboarding/getting_started.html.twig', [
            'progress' => $profile !== null ? $this->getGettingStartedProgress->forPlayer($profile) : null,
        ]);
    }
}
