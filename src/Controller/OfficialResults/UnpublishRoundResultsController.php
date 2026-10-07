<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\UnpublishRoundResults;
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
 * Takes the round's official results off the round page (UnpublishRoundResults) - an empty JSON body `{}`; answers with the round.
 */
final class UnpublishRoundResultsController extends AbstractController
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
        path: '/{_locale}/official-results/rounds/{roundId}/unpublish',
        name: 'official_results_unpublish',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $authorised = $this->api->authorise($request, $competitionId, write: true);

        if ($authorised instanceof JsonResponse) {
            return $authorised;
        }

        $this->messageBus->dispatch(new UnpublishRoundResults($competitionId, $round->id->toString()));
        $this->liveUpdates->roundChanged($round->id->toString());

        return OfficialResultsApi::json(['round' => $this->getRoundResultsOverview->forRound($round->id->toString())]);
    }
}
