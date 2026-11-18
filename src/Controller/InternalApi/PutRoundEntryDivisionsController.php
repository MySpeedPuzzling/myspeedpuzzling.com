<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Message\SetRoundEntryDivisions;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Value\EntryDivisionInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Divisions of a round's entries in bulk (SetRoundEntryDivisions; docs/features/internal-api.md "Divisions",
 * shared-puzzles-and-divisions.md A10) - the demo fallback until the participants sheet has a Division column.
 *
 * Body: `entries` (required, ≤ 1000): `[{"participantId" | "teamId", "division": "<id, name or short label>" | null}]` -
 * a solo round's entry by its participant, a pair/team by its team or one of its members. All or nothing: an unknown
 * entry (`unknown_entry`) or a division the round does not use (`not_in_round`, `unknown_division`) answers 422.
 * Answers `{"roundId", "changed", "entries": [{"ref", "participantId", "teamId", "name", "division", "divisionRank"}]}`
 * - every entry of the round after the change.
 */
final class PutRoundEntryDivisionsController extends AbstractController
{
    private const int MAX_ENTRIES = 1000;

    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly GetRoundResultEntries $getRoundResultEntries,
        private readonly MessageBusInterface $messageBus,
        private readonly OfficialResultsLiveUpdates $officialResultsLiveUpdates,
    ) {
    }

    #[Route(
        path: '/internal-api/rounds/{roundId}/entry-divisions',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PUT'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $roundId = $round->id->toString();
        $body = InternalApiJsonBody::parse($request);
        $errors = [];

        foreach (array_keys($body) as $field) {
            if ($field !== 'entries') {
                $errors[(string) $field] = 'is not a field of this endpoint (known: entries).';
            }
        }

        $list = $body['entries'] ?? null;
        if (!is_array($list) || !array_is_list($list) || $list === [] || count($list) > self::MAX_ENTRIES) {
            $errors['entries'] = sprintf('is required: a list of 1 to %d {"participantId" | "teamId", "division"}.', self::MAX_ENTRIES);
            $list = [];
        }

        $entries = [];
        foreach ($list as $index => $item) {
            $field = sprintf('entries[%d]', $index);

            if (!is_array($item) || array_diff(array_keys($item), ['participantId', 'teamId', 'division']) !== [] || !array_key_exists('division', $item)) {
                $errors[$field] = 'must be an object with "participantId" or "teamId", and "division".';

                continue;
            }

            $participantId = $item['participantId'] ?? null;
            $teamId = $item['teamId'] ?? null;
            $division = $item['division'];

            if (($participantId === null) === ($teamId === null)) {
                $errors[$field] = 'needs exactly one of "participantId" and "teamId".';

                continue;
            }

            foreach (['participantId' => $participantId, 'teamId' => $teamId] as $name => $value) {
                if ($value !== null && (!is_string($value) || !Uuid::isValid($value))) {
                    $errors[$field . '.' . $name] = 'must be a UUID.';
                }
            }

            if ($division !== null && !is_string($division)) {
                $errors[$field . '.division'] = 'must be a division id, name or short label, or null.';
            }

            if (isset($errors[$field . '.participantId']) || isset($errors[$field . '.teamId']) || isset($errors[$field . '.division'])) {
                continue;
            }

            assert(($participantId === null || is_string($participantId)) && ($teamId === null || is_string($teamId)) && ($division === null || is_string($division)));
            $entries[] = new EntryDivisionInput($participantId, $teamId, $division);
        }

        if ($errors !== []) {
            throw new InternalApiInvalidInput($errors);
        }

        $envelope = $this->messageBus->dispatch(new SetRoundEntryDivisions(
            competitionId: $round->competition->id->toString(),
            roundId: $roundId,
            entries: $entries,
        ));
        $changed = $envelope->last(HandledStamp::class)?->getResult();

        // After the commit: the round's open desks read it again
        if (is_int($changed) && $changed > 0) {
            $this->officialResultsLiveUpdates->refresh($roundId);
        }

        return new JsonResponse([
            'roundId' => $roundId,
            'changed' => is_int($changed) ? $changed : 0,
            'entries' => array_map(static fn (RoundResultEntry $entry): array => [
                'ref' => $entry->ref->toString(),
                'participantId' => $entry->participantId,
                'teamId' => $entry->kind === RoundResultEntry::KIND_TEAM ? $entry->ref->id : null,
                'name' => $entry->displayName(),
                'division' => $entry->division?->name,
                'divisionRank' => $entry->divisionRank,
            ], $this->getRoundResultEntries->forRound($roundId)),
        ]);
    }
}
