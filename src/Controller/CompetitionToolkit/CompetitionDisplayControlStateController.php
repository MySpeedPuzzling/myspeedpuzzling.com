<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetDisplayControlState;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayControlEntries;
use SpeedPuzzling\Web\Services\LiveResultsCurrentRound;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The live control's data (competition toolkit v2 L1/L2, displays-standings.md §5.3) - `?round=` one of the event's
 * rounds, else the current one (LiveResultsCurrentRound): `{roundId, message, recentMessages, displays, elements:
 * {message, spotlight}, spotlight: {entryRef, name, table, tableLabel?}|null, entries: [{ref, table, tableLabel?, name, members,
 * finishedAtMs}]}` -
 * the round's entries for the spotlight picker with the organiser's names (did not start left out). Organisers only (COMPETITION_TOOLKIT_MANAGE), `private, no-store`, 401/403 as JSON.
 */
final class CompetitionDisplayControlStateController extends AbstractController
{
    public function __construct(
        private readonly OfficialResultsApi $api,
        private readonly GetDisplayControlState $getDisplayControlState,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly GetRoundResultEntries $getRoundResultEntries,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/ovladani-obrazovek/{competitionId}/stav',
            'en' => '/en/display-control/{competitionId}/state',
            'es' => '/es/display-control/{competitionId}/state',
            'ja' => '/ja/display-control/{competitionId}/state',
            'fr' => '/fr/display-control/{competitionId}/state',
            'de' => '/de/display-control/{competitionId}/state',
        ],
        name: 'competition_display_control_state',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId): JsonResponse
    {
        $playerId = $this->api->authorise($request, $competitionId, write: false, attribute: CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE);

        if ($playerId instanceof JsonResponse) {
            return $playerId;
        }

        $rounds = $this->getRoundResultsOverview->forCompetition($competitionId);
        $requested = strtolower($request->query->getString('round'));
        $round = null;

        foreach ($rounds as $candidate) {
            if (strtolower($candidate->roundId) === $requested) {
                $round = $candidate;
            }
        }

        $round ??= LiveResultsCurrentRound::pick($rounds, $this->clock->now());
        $entries = [];
        $spotlight = null;

        if ($round !== null) {
            $entries = DisplayControlEntries::forPicker($this->getRoundResultEntries->forRound($round->roundId), $round->tableLabels);
            $ref = $this->getDisplayControlState->spotlightRef($round->roundId);

            if ($ref !== null) {
                $entry = array_values(array_filter($entries, static fn (array $chip): bool => $chip['ref'] === $ref))[0] ?? null;
                // An entry gone meanwhile (removed from the event): the displays show nobody - the picker names nobody
                $spotlight = ['entryRef' => $ref, 'name' => $entry['name'] ?? null, 'table' => $entry['table'] ?? null];

                // A labelled round's table name (table-labels-and-plates.md A4) - the key only when set
                if (isset($entry['tableLabel'])) {
                    $spotlight['tableLabel'] = $entry['tableLabel'];
                }
            }
        }

        return OfficialResultsApi::json([
            'roundId' => $round?->roundId,
            ...$this->getDisplayControlState->ofCompetition($competitionId),
            'spotlight' => $spotlight,
            'entries' => $entries,
        ]);
    }
}
