<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OfficialResultsProtected;
use SpeedPuzzling\Web\Exceptions\RoundEntryNotFound;
use SpeedPuzzling\Web\Message\TakeEntryOutOfRound;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Take out of this round" (TakeEntryOutOfRound): `{"entry": "participant_round:<id>"}` → `{removed: ref, round}`;
 * 409 `entry_protected` (+ `message`) while the entry has a result or a qualified mark, 404 `entry_not_found`.
 */
final class TakeEntryOutOfRoundController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly OfficialResultsApi $api,
        private readonly OfficialResultsLiveUpdates $liveUpdates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/official-results/rounds/{roundId}/take-out',
        name: 'official_results_take_out',
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

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $entry = $body['entry'] ?? null;

        if (!is_string($entry)) {
            return OfficialResultsApi::error('invalid_entry', JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $this->messageBus->dispatch(new TakeEntryOutOfRound($competitionId, $round->id->toString(), $entry));
        } catch (RoundEntryNotFound) {
            return OfficialResultsApi::error('entry_not_found', JsonResponse::HTTP_NOT_FOUND, [
                'message' => $this->translator->trans('official_results.reason.entry_not_found'),
            ]);
        } catch (OfficialResultsProtected $protected) {
            return OfficialResultsApi::error('entry_protected', JsonResponse::HTTP_CONFLICT, [
                'message' => $this->translator->trans($protected->translationKey()),
            ]);
        }

        $this->liveUpdates->refresh($round->id->toString());

        return OfficialResultsApi::json([
            'removed' => $entry,
            'round' => $this->getRoundResultsOverview->forRound($round->id->toString()),
        ]);
    }
}
