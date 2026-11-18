<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The old "Add edition" address - the classic one-edition form was retired on 2026-11-18 (creation-wizard.md "Classic
 * create retired"): every request leads to the wizard's "Add the next dates" of the series (`event_wizard_series_next_dates`
 * - one picked day is one date), which checks who may add dates. A POST (the form left open in a tab) creates nothing:
 * 303 there. An edit of an edition stays on `edit_competition`.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddEditionController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/pridat-edici/{seriesId}',
            'en' => '/en/add-edition/{seriesId}',
            'es' => '/es/add-edition/{seriesId}',
            'ja' => '/ja/add-edition/{seriesId}',
            'fr' => '/fr/add-edition/{seriesId}',
            'de' => '/de/add-edition/{seriesId}',
        ],
        name: 'add_edition',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
    )]
    public function __invoke(Request $request, string $seriesId): Response
    {
        return $this->redirectToRoute(
            'event_wizard_series_next_dates',
            ['seriesId' => $seriesId],
            $request->isMethod('GET') ? Response::HTTP_FOUND : Response::HTTP_SEE_OTHER,
        );
    }
}
