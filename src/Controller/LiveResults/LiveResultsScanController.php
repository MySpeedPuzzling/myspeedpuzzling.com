<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LiveResults;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Query\GetLiveResultsEntrant;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\LiveResultsCurrentRound;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The URL in a name tag's QR (docs/features/competitions-management/live-results.md). An organiser of the event
 * (their phone's camera) lands on the live entry of the participant's current round with their entry open - their
 * own round when they are in several, so parallel halls each get theirs. An organiser scanning somebody who is no
 * (longer a) participant of the event gets the current round's live entry saying so; with no round at all, the rounds
 * page saying that. Anyone else - signed out, a puzzler - lands on the event page.
 */
final class LiveResultsScanController extends AbstractController
{
    public function __construct(
        private readonly GetLiveResultsEntrant $getLiveResultsEntrant,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
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
    public function __invoke(string $competitionId, string $participantId): RedirectResponse
    {
        $organiser = $this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);
        $entrant = $organiser ? $this->getLiveResultsEntrant->ofCompetition($competitionId, $participantId) : null;
        $round = null;

        if ($organiser) {
            $rounds = $this->getRoundResultsOverview->forCompetition($competitionId);
            $theirs = array_values(array_filter(
                $rounds,
                static fn (RoundResultsOverview $round): bool => $entrant !== null && in_array($round->roundId, $entrant->roundIds, true),
            ));
            $now = $this->clock->now();
            $round = LiveResultsCurrentRound::pick($theirs, $now) ?? LiveResultsCurrentRound::pick($rounds, $now);
        }

        if ($organiser && $round === null) {
            // Nothing to enter results in yet - the organiser learns why instead of landing on the public page
            $this->addFlash('warning', $this->translator->trans('live_results.scan.no_rounds'));
            $response = $this->redirectToRoute('manage_competition_rounds', ['competitionId' => $competitionId]);
        } elseif ($organiser) {
            // Somebody removed from the event, or a tag of another one: the live entry says so
            $response = $this->redirectToRoute('live_results', $entrant !== null
                ? ['roundId' => $round->roundId, 'entrant' => $entrant->participantId]
                : ['roundId' => $round->roundId, 'notice' => 'tag_unknown']);
        } else {
            try {
                $response = $this->redirect($this->competitionDetailUrl->of($competitionId));
            } catch (CompetitionNotFound) {
                $response = $this->redirectToRoute('events');
            }
        }

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
