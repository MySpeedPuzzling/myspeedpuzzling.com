<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LiveResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\CompetitionTagLanding;
use SpeedPuzzling\Web\Services\PreferredLocale;
use SpeedPuzzling\Web\Value\CompetitionTag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A printed line-up tag - a pair/team for the whole competition (competition toolkit B-qr-competitor-page.md §3.2,
 * CompetitionLineupId). Frozen path without a language (R-review-and-plan.md §1.3, short for a small QR): the viewer's
 * language is picked (PreferredLocale); a language prefix is accepted too. Where it leads: CompetitionTagLanding.
 */
final class LiveResultsLineupTagController extends AbstractController
{
    public function __construct(
        private readonly CompetitionTagLanding $competitionTagLanding,
        private readonly PreferredLocale $preferredLocale,
    ) {
    }

    #[Route(
        path: '/live/{competitionId}/g/{lineupId}',
        name: 'live_results_lineup_tag',
        requirements: [
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
            'lineupId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    #[Route(
        path: '/{_locale}/live/{competitionId}/g/{lineupId}',
        name: 'live_results_lineup_tag_localized',
        requirements: [
            '_locale' => 'en|cs|es|ja|fr|de',
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
            'lineupId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId, string $lineupId): RedirectResponse
    {
        $locale = $request->attributes->has('_locale') ? $request->getLocale() : $this->preferredLocale->of($request);

        return $this->competitionTagLanding->redirect(strtolower($competitionId), CompetitionTag::lineup($lineupId), $locale);
    }
}
