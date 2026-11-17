<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LiveResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\CompetitionTagLanding;
use SpeedPuzzling\Web\Value\CompetitionTag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The URL in a name tag's QR (docs/features/competitions-management/live-results.md) - printed, so its path never
 * changes (competition toolkit R-review-and-plan.md §1.3). An organiser or referee of the event (their phone's camera)
 * lands on the live entry of the participant's current round with their entry open - their own round when they are in
 * several, so parallel halls each get theirs. Scanning somebody who is no (longer a) participant of the event opens the
 * current round's live entry saying so; with no round at all, an organiser gets the rounds page and a referee the event
 * page, both saying that. Anyone else - signed out, a puzzler - lands on the event page, or on the person's competitor
 * page when the event has competitor pages on (CompetitionTagLanding).
 */
final class LiveResultsScanController extends AbstractController
{
    public function __construct(
        private readonly CompetitionTagLanding $competitionTagLanding,
    ) {
    }

    #[Route(
        path: '/{_locale}/live/{competitionId}/p/{participantId}',
        name: 'live_results_scan',
        requirements: [
            '_locale' => 'en|cs|es|ja|fr|de',
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
            'participantId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId, string $participantId): RedirectResponse
    {
        return $this->competitionTagLanding->redirect($competitionId, CompetitionTag::participant($participantId), $request->getLocale());
    }
}
