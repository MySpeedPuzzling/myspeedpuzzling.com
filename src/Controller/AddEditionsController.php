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
 * The old "Add several dates" address - retired on 2026-11-18 for the wizard's "Add the next dates"
 * (`event_wizard_series_next_dates`, the same rule, preview and names - EventWizardDatesFormData extends
 * AddEditionsFormData - plus each date's round; creation-wizard.md "Classic create retired"). A GET keeps its query
 * string (a bookmarked rule opens the same preview there); a POST (the page left open in a tab) creates nothing: 303 to
 * the rule's preview, where the dates are checked and created again - never half saved.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddEditionsController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/pridat-edice/{seriesId}',
            'en' => '/en/add-editions/{seriesId}',
            'es' => '/es/add-editions/{seriesId}',
            'ja' => '/ja/add-editions/{seriesId}',
            'fr' => '/fr/add-editions/{seriesId}',
            'de' => '/de/add-editions/{seriesId}',
        ],
        name: 'add_editions',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $seriesId): Response
    {
        // The rule's fields only - never the classic page's own return link
        $query = array_diff_key($request->query->all(), ['return' => true, 'return_title' => true]);

        return $this->redirectToRoute(
            'event_wizard_series_next_dates',
            ['seriesId' => $seriesId, ...$query],
            $request->isMethod('GET') ? Response::HTTP_FOUND : Response::HTTP_SEE_OTHER,
        );
    }
}
