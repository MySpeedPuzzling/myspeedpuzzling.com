<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Message\SetCompetitionDivisions;
use SpeedPuzzling\Web\Query\GetCompetitionDivisions;
use SpeedPuzzling\Web\Repository\CompetitionDivisionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Value\DivisionInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An event's divisions, the whole list at once - what the divisions page saves (SetCompetitionDivisions;
 * docs/features/internal-api.md "Divisions", shared-puzzles-and-divisions.md A10).
 *
 * Body: `divisions` (required, in their order, ≤ 12): `[{"id"?, "name", "shortName"?, "roundIds"?: [...]}]` - an `id` of
 * the event's division keeps it (renamed as sent); no id, or an id that is not one of the event's divisions, = a new
 * one with the server's own id; a division left out is deleted. `roundIds` = the
 * rounds that use it (left out = none). `confirmClearing` (bool): take the division from the entries that would lose it
 * - without it such a save answers 409 `divisions_in_use`. Refusals 422 with `code` = InvalidCompetitionDivisions'
 * reason, nothing changed. Answers the list as GET does.
 */
final class PutCompetitionDivisionsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly CompetitionDivisionRepository $divisionRepository,
        private readonly GetCompetitionDivisions $getCompetitionDivisions,
        private readonly MessageBusInterface $messageBus,
        private readonly OfficialResultsLiveUpdates $officialResultsLiveUpdates,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/divisions',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PUT'],
    )]
    public function __invoke(string $competitionId, Request $request): JsonResponse
    {
        $competition = $this->competitionRepository->get($competitionId);
        $competitionId = $competition->id->toString();
        // Ids the client may keep - a new division always gets the server's own id
        $ownIds = [];
        foreach ($this->divisionRepository->ofCompetition($competition) as $division) {
            $ownIds[strtolower($division->id->toString())] = true;
        }
        $body = InternalApiJsonBody::parse($request);
        $errors = [];

        foreach (array_keys($body) as $field) {
            if (!in_array($field, ['divisions', 'confirmClearing'], true)) {
                $errors[(string) $field] = 'is not a field of this endpoint (known: divisions, confirmClearing).';
            }
        }

        $confirmClearing = $body['confirmClearing'] ?? false;
        if (!is_bool($confirmClearing)) {
            $errors['confirmClearing'] = 'must be a boolean.';
        }

        $divisions = [];
        $rounds = [];
        $list = $body['divisions'] ?? null;

        if (!is_array($list) || !array_is_list($list)) {
            $errors['divisions'] = 'is required: a list of {"id"?, "name", "shortName"?, "roundIds"?}.';
            $list = [];
        }

        foreach ($list as $index => $item) {
            $field = sprintf('divisions[%d]', $index);

            if (!is_array($item) || array_diff(array_keys($item), ['id', 'name', 'shortName', 'roundIds']) !== []) {
                $errors[$field] = 'must be an object with "id"?, "name", "shortName"?, "roundIds"?.';

                continue;
            }

            $id = $item['id'] ?? null;
            $name = $item['name'] ?? null;
            $shortName = $item['shortName'] ?? null;
            $roundIds = $item['roundIds'] ?? [];

            if ($id !== null && (!is_string($id) || !Uuid::isValid($id))) {
                $errors[$field . '.id'] = 'must be a UUID.';
            }

            if (!is_string($name)) {
                $errors[$field . '.name'] = 'is required.';
            }

            if ($shortName !== null && !is_string($shortName)) {
                $errors[$field . '.shortName'] = 'must be a string or null.';
            }

            if (!is_array($roundIds) || !array_is_list($roundIds) || array_any($roundIds, static fn (mixed $roundId): bool => !is_string($roundId) || !Uuid::isValid($roundId))) {
                $errors[$field . '.roundIds'] = 'must be a list of round UUIDs.';
                $roundIds = [];
            }

            if (!is_string($name) || ($id !== null && !is_string($id)) || ($shortName !== null && !is_string($shortName))) {
                continue;
            }

            $divisionId = is_string($id) && isset($ownIds[strtolower($id)]) ? strtolower($id) : Uuid::uuid7()->toString();
            $divisions[] = new DivisionInput($divisionId, $name, $shortName);

            /** @var list<string> $roundIds */
            foreach ($roundIds as $roundId) {
                $rounds[strtolower($roundId)][] = $divisionId;
            }
        }

        if ($errors !== []) {
            throw new InternalApiInvalidInput($errors);
        }

        assert(is_bool($confirmClearing));

        $envelope = $this->messageBus->dispatch(new SetCompetitionDivisions(
            competitionId: $competitionId,
            divisions: $divisions,
            rounds: $rounds,
            confirmClearing: $confirmClearing,
        ));

        // After the commit, like the page: the open desks of the rounds that changed read the round again
        /** @var list<string> $changedRounds */
        $changedRounds = $envelope->last(HandledStamp::class)?->getResult() ?? [];
        foreach ($changedRounds as $roundId) {
            $this->officialResultsLiveUpdates->refresh($roundId);
        }

        return new JsonResponse([
            'competitionId' => $competitionId,
            'divisions' => $this->getCompetitionDivisions->ofCompetition($competitionId),
        ]);
    }
}
