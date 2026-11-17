<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\StopwatchMissedRevealChanged;
use SpeedPuzzling\Web\Message\PublishRoundResults;
use SpeedPuzzling\Web\Message\RevealPuzzlesTheStopwatchMissed;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publishes the round's official results (PublishRoundResults) - a JSON body `{}`; answers with the round.
 *
 * `{"revealMissed": true, "revealHash": "…"}` also reveals the round's secret puzzles the stopwatch start never revealed
 * ("Also reveal the hidden puzzles now" of the desk's publish dialog, competition toolkit G3, R-review-and-plan.md
 * §2.1.5): AFTER the publish, in a dispatch of its own - the results are published whatever the reveal answers. The
 * answer then carries `reveal`: `revealed`, or `changed` when the list differs from the confirmed one (nothing revealed).
 * Then the reveal push (OfficialResultsLiveUpdates::puzzlesRevealed()), like "Reveal now".
 */
final class PublishRoundResultsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly OfficialResultsApi $api,
        private readonly OfficialResultsLiveUpdates $liveUpdates,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/rounds/{roundId}/publish',
        name: 'official_results_publish',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $roundId = $round->id->toString();
        $authorised = $this->api->authorise($request, $competitionId, write: true);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        $body = OfficialResultsApi::bodyOrEmpty($request);

        $this->messageBus->dispatch(new PublishRoundResults($competitionId, $roundId));

        $reveal = null;

        if (($body['revealMissed'] ?? false) === true) {
            $reveal = $this->revealWhatTheStopwatchMissed($competitionId, $roundId, $body['revealHash'] ?? null);
        }

        $this->liveUpdates->roundChanged($roundId);

        if ($reveal === 'revealed') {
            // After the commit - the reveal push: the round's open pages read its puzzles again
            $this->liveUpdates->puzzlesRevealed($roundId);
        }

        $answer = ['round' => $this->getRoundResultsOverview->forRound($roundId)];

        if ($reveal !== null) {
            $answer['reveal'] = $reveal;
        }

        return OfficialResultsApi::json($answer);
    }

    /**
     * @return 'revealed'|'changed'
     */
    private function revealWhatTheStopwatchMissed(string $competitionId, string $roundId, mixed $confirmedHash): string
    {
        if (!is_string($confirmedHash) || $confirmedHash === '') {
            return 'changed';
        }

        try {
            $this->messageBus->dispatch(new RevealPuzzlesTheStopwatchMissed($competitionId, $roundId, $confirmedHash));
        } catch (StopwatchMissedRevealChanged) {
            return 'changed';
        }

        return 'revealed';
    }
}
