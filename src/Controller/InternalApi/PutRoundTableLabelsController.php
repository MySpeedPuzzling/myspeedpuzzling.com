<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Exceptions\InvalidTableLabels;
use SpeedPuzzling\Web\Exceptions\TableLabelsRefused;
use SpeedPuzzling\Web\Message\SetRoundTableLabels;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Value\TableLabelGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A round's table names (docs/features/competitions-management/table-labels-and-plates.md, internal-api.md "Table
 * names") - the seating page's message, SetRoundTableLabels. GET answers `{"roundId", "labels": [...] | null}`.
 *
 * PUT body: exactly one of `labels` (`["A1 L", "A1 R", …]` or null = plain numbers again) and `generate`
 * (`{"letters": "A-H", "numbers": "1-22", "seats": 2, "seatStyle": "side"|"letter"|"dash", "order": "letters"|"numbers",
 * "skip": "H22"}` - TableLabelGenerator), plus optional `alsoRounds` (ids of more rounds of the event that get the same
 * list). All or nothing; the lists the rounds have now are the expected ones. 422 `invalid_labels` / `labels_refused`
 * with `problems` (a round where somebody sits at a number beyond the list: `too_few` + `highest`).
 */
final class PutRoundTableLabelsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly OfficialResultsLiveUpdates $officialResultsLiveUpdates,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
    ) {
    }

    #[Route(
        path: '/internal-api/rounds/{roundId}/table-labels',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'PUT'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        $round = $this->roundRepository->get($roundId);
        $roundId = $round->id->toString();

        if ($request->isMethod('GET')) {
            return new JsonResponse(['roundId' => $roundId, 'labels' => $round->tableLabels]);
        }

        $body = InternalApiJsonBody::parse($request);
        $errors = [];

        foreach (array_keys($body) as $field) {
            if (!in_array($field, ['labels', 'generate', 'alsoRounds'], true)) {
                $errors[(string) $field] = 'is not a field of this endpoint (known: labels, generate, alsoRounds).';
            }
        }

        if (array_key_exists('labels', $body) === array_key_exists('generate', $body)) {
            $errors['labels'] = 'send exactly one of "labels" (a list of strings or null) and "generate".';
        }

        $labels = null;

        if (array_key_exists('labels', $body) && $body['labels'] !== null) {
            if (!is_array($body['labels']) || !array_is_list($body['labels']) || !array_all($body['labels'], static fn (mixed $label): bool => is_string($label))) {
                $errors['labels'] = 'must be a list of strings, or null for plain numbers.';
            } else {
                /** @var list<string> $labels */
                $labels = $body['labels'];
            }
        }

        $generate = $body['generate'] ?? null;

        if (array_key_exists('generate', $body)) {
            if (!is_array($generate)) {
                $errors['generate'] = 'must be an object {letters, numbers, seats, seatStyle, order, skip}.';
            } else {
                try {
                    $labels = TableLabelGenerator::generate(
                        letters: is_string($generate['letters'] ?? null) ? $generate['letters'] : '',
                        numbers: is_string($generate['numbers'] ?? null) ? $generate['numbers'] : '',
                        seats: is_int($generate['seats'] ?? null) ? $generate['seats'] : 1,
                        seatStyle: is_string($generate['seatStyle'] ?? null) ? $generate['seatStyle'] : 'dash',
                        order: is_string($generate['order'] ?? null) ? $generate['order'] : 'letters',
                        skip: is_string($generate['skip'] ?? null) ? $generate['skip'] : '',
                    );
                } catch (InvalidTableLabels $invalid) {
                    $errors['generate'] = sprintf('refused: %s.', json_encode($invalid->problems, JSON_THROW_ON_ERROR));
                }
            }
        }

        $roundIds = [$roundId];
        $also = $body['alsoRounds'] ?? [];

        if (!is_array($also) || !array_is_list($also)) {
            $errors['alsoRounds'] = 'must be a list of round ids.';
            $also = [];
        }

        foreach ($also as $index => $otherId) {
            if (!is_string($otherId) || preg_match('/^' . FirstTryConflictsController::ID_REQUIREMENT . '$/', $otherId) !== 1) {
                $errors[sprintf('alsoRounds[%d]', $index)] = 'must be a round id.';

                continue;
            }

            $roundIds[] = strtolower($otherId);
        }

        if ($errors !== []) {
            throw new InternalApiInvalidInput($errors);
        }

        $roundIds = array_values(array_unique($roundIds));
        $expected = [];

        foreach ($roundIds as $id) {
            $expected[$id] = $this->roundRepository->get($id)->tableLabels;
        }

        try {
            $envelope = $this->messageBus->dispatch(new SetRoundTableLabels($round->competition->id->toString(), $roundIds, $labels, $expected));
        } catch (InvalidTableLabels $invalid) {
            return new JsonResponse(['error' => 'invalid_labels', 'problems' => $invalid->problems], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (TableLabelsRefused $refused) {
            return new JsonResponse(['error' => 'labels_refused', 'problems' => $refused->problems], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var list<string> $changed */
        $changed = $envelope->last(HandledStamp::class)?->getResult() ?? [];

        foreach ($changed as $changedRoundId) {
            $this->officialResultsLiveUpdates->roundChanged($changedRoundId);
            $this->displayLiveUpdates->roundChanged($changedRoundId);
        }

        return new JsonResponse(['roundIds' => $roundIds, 'changed' => $changed, 'labels' => $labels, 'count' => $labels === null ? 0 : count($labels)]);
    }
}
