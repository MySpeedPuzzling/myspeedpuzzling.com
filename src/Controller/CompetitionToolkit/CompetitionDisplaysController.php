<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplaysPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Every display and overlay of an event (docs/features/competitions-management/toolkit/D-displays-overlays.md §8.1) -
 * the full list behind the toolkit hub's Displays card. Only for whom COMPETITION_TOOLKIT_MANAGE grants.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionDisplaysController extends AbstractController
{
    public function __construct(
        private readonly CompetitionDisplaysPage $page,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-obrazovek/{competitionId}',
            'en' => '/en/manage-displays/{competitionId}',
            'es' => '/es/manage-displays/{competitionId}',
            'ja' => '/ja/manage-displays/{competitionId}',
            'fr' => '/fr/manage-displays/{competitionId}',
            'de' => '/de/manage-displays/{competitionId}',
        ],
        name: 'competition_displays',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        $response = $this->render('competition_toolkit/displays.html.twig', $this->page->variables($request, $competitionId));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
