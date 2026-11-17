<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use DateTimeImmutable;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What "Also reveal the hidden puzzles now" of the results desk's publish dialog would reveal (competition toolkit G3):
 * the round's secret puzzles the stopwatch start never revealed (SecretRevealPreview::byRevealingWhatTheStopwatchMissed()),
 * each with how far it comes out (`scope`, and until when it stays hidden elsewhere), and the `hash` the publish sends
 * back to confirm exactly this list. The event's organisers only (COMPETITION_EDIT - never a referee): it names the
 * puzzles, like the stopwatch control page and the round puzzles page do for them. Read-only, never cached.
 */
final class StopwatchMissedRevealController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly OfficialResultsApi $api,
        private readonly SecretRevealPreview $secretRevealPreview,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/rounds/{roundId}/missed-reveal',
        name: 'official_results_missed_reveal',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $authorised = $this->api->authorise($request, $round->competition->id->toString(), write: false);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        $revealed = $this->secretRevealPreview->byRevealingWhatTheStopwatchMissed($round);

        return OfficialResultsApi::json([
            'puzzles' => array_map(static fn (array $item): array => [
                'name' => $item['name'],
                'scope' => $item['scope'],
                'hiddenElsewhereUntil' => $item['hiddenElsewhereUntil']?->format(DateTimeImmutable::ATOM),
            ], $revealed),
            'hash' => SecretRevealPreview::hash($revealed),
        ]);
    }
}
