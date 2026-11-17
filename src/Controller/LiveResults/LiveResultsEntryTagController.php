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
 * A printed round-entry tag - one round's entry with its table, static (competition toolkit B-qr-competitor-page.md
 * §3.1): the bare CompetitionParticipantRound or CompetitionTeam id, no round id in the path - the round is the entry's
 * own. Frozen path without a language (R-review-and-plan.md §1.3, short for a small QR): the viewer's language is
 * picked (PreferredLocale); a language prefix is accepted too. Where it leads: CompetitionTagLanding.
 */
final class LiveResultsEntryTagController extends AbstractController
{
    public function __construct(
        private readonly CompetitionTagLanding $competitionTagLanding,
        private readonly PreferredLocale $preferredLocale,
    ) {
    }

    #[Route(
        path: '/live/{competitionId}/e/{entryId}',
        name: 'live_results_entry_tag',
        requirements: [
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
            'entryId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    #[Route(
        path: '/{_locale}/live/{competitionId}/e/{entryId}',
        name: 'live_results_entry_tag_localized',
        requirements: [
            '_locale' => 'en|cs|es|ja|fr|de',
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
            'entryId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId, string $entryId): RedirectResponse
    {
        $locale = $request->attributes->has('_locale') ? $request->getLocale() : $this->preferredLocale->of($request);

        return $this->competitionTagLanding->redirect(strtolower($competitionId), CompetitionTag::entry($entryId), $locale);
    }
}
