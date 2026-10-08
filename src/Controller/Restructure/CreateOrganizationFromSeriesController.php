<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Restructure;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Turn into an organization" (docs/features/organizations/README.md "Restructuring tools") - SKELETON of the
 * foundation: the route and its access rule. Workstream D builds the page (→ CreateOrganizationFromSeries).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CreateOrganizationFromSeriesController extends AbstractController
{
    #[Route(
        path: '/{_locale}/series-to-organization/{seriesId}',
        name: 'create_organization_from_series',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $seriesId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId);

        throw new NotFoundHttpException();
    }
}
