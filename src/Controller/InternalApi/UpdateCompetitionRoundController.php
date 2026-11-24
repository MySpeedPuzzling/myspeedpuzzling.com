<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;

/**
 * Changes only the fields sent. The round's slug never changes (shared result links keep working). A category change
 * may make the round share a puzzle with another round of the new category - accepted, the answer's `sharedWithRounds`
 * says so per puzzle.
 *
 * Any change that moves the round's automatic reveal (start + `revealDelayMinutes`) EARLIER - by the start, the delay or
 * both, the net moment decides - lets its secret puzzles with an automatic reveal out earlier than planned: refused
 * (409, `revealedPuzzles` - each with when, `rightAway` / `revealsAt`, and how far, `scope`) unless the body says
 * `"confirmReveal": true`. A later moment needs no yes and hides them longer on the whole site too.
 *
 * `puzzleFormat` / `puzzlesToSolve` set the round's format (spws-wizard.md package D) through the same message and checks
 * as the round form - `RoundPuzzleFormatRefused` is a 409.
 */
final class UpdateCompetitionRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
        private readonly OfficialResultsLiveUpdates $officialResultsLiveUpdates,
    ) {
    }

    #[Route(
        path: '/internal-api/rounds/{roundId}',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PATCH'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $input = InternalApiInput::fromRequest($request, [...RoundInput::FIELDS, 'confirmReveal', 'puzzleFormat', 'puzzlesToSolve']);

        // The round as stored - in its own zone; a field left out keeps its value (its start too, to the second)
        $data = CompetitionRoundFormData::fromCompetitionRound($round);
        $startsAt = RoundInput::applyTo($input, $data) ?? $round->startsAt;
        $confirmReveal = $input->bool('confirmReveal') ?? false;
        [$puzzleFormat, $puzzlesToSolve] = self::puzzleFormat($input, $round);

        $input->addViolations($this->validator->validate($data));
        $input->throwIfInvalid();

        assert($data->name !== null && $data->minutesLimit !== null && $data->timezone !== null);

        $this->messageBus->dispatch(new EditCompetitionRound(
            roundId: $round->id->toString(),
            competitionId: $round->competition->id->toString(),
            name: $data->name,
            minutesLimit: $data->minutesLimit,
            startsAt: $startsAt,
            timezone: $data->timezone,
            badgeBackgroundColor: $data->badgeBackgroundColor,
            badgeTextColor: $data->badgeTextColor,
            category: $data->category,
            resultsLink: $data->resultsLink,
            // An automatic reveal moved earlier (start and/or delay) needs an explicit yes (409 otherwise)
            refuseToReveal: $confirmReveal === false,
            // What the body leaves out is kept as the round has it under the handler's lock - the values above
            // for those fields were read before it and are ignored
            keepFields: self::keptFields($input),
            // Left out = null = the round's delay as it is under the handler's lock
            revealDelayMinutes: $input->has('revealDelayMinutes') ? $data->revealDelayMinutes : null,
            // Left out = kept as the round has it under the handler's lock; `null` takes it away
            teamSize: $input->has('teamSize') ? $data->teamSize : null,
            clearTeamSize: $input->has('teamSize') && $data->teamSize === null,
            // Left out = the round's format as it is under the handler's lock; refused (409) outside 1..N puzzles, on a
            // round using online timing or when entries' per-puzzle records would break (RoundPuzzleFormatRefused)
            puzzleFormat: $puzzleFormat,
            puzzlesToSolve: $puzzlesToSolve,
        ));

        // After the commit (competition toolkit D5)
        $this->displayLiveUpdates->roundChanged($round->id->toString());

        // A format sent: open judge pages and desks read the round again (the round form's push)
        if ($puzzleFormat !== null) {
            $this->officialResultsLiveUpdates->roundChanged($round->id->toString());
        }

        return new JsonResponse($this->getAdminCompetitions->round($round->id->toString())->toArray());
    }

    /**
     * `puzzleFormat` (`total` | `pick`) and `puzzlesToSolve` (K of `pick`; K = the round's puzzles = "solve all") - the
     * round form's toolkit field (competition toolkit A2). Either one alone keeps the other as the round has it; nothing
     * sent = [null, null] = the format is kept.
     *
     * @return array{null|RoundPuzzleFormat, null|int}
     */
    private static function puzzleFormat(InternalApiInput $input, CompetitionRound $round): array
    {
        if ($input->has('puzzleFormat') === false && $input->has('puzzlesToSolve') === false) {
            return [null, null];
        }

        $format = $round->puzzleFormat;

        if ($input->has('puzzleFormat')) {
            $sent = $input->string('puzzleFormat', required: true);
            $format = $sent !== null ? RoundPuzzleFormat::tryFrom($sent) : null;

            if ($format === null) {
                $input->addError('puzzleFormat', sprintf('must be one of: %s.', implode(', ', array_column(RoundPuzzleFormat::cases(), 'value'))));

                return [null, null];
            }
        }

        if ($format === RoundPuzzleFormat::Total) {
            if ($input->has('puzzlesToSolve') && $input->int('puzzlesToSolve') !== null) {
                $input->addError('puzzlesToSolve', 'is only for "pick" - send null or leave it out for "total".');
            }

            return [RoundPuzzleFormat::Total, null];
        }

        $toSolve = $input->has('puzzlesToSolve')
            ? $input->int('puzzlesToSolve', required: true, minimum: 1, maximum: 100)
            : $round->puzzlesToSolve;

        if ($toSolve === null && $input->hasError('puzzlesToSolve') === false) {
            $input->addError('puzzlesToSolve', 'is required for "pick": how many of the round\'s puzzles each entrant solves (all of them = "solve all").');
        }

        return [RoundPuzzleFormat::Pick, $toSolve];
    }

    /**
     * @return list<string>
     */
    private static function keptFields(InternalApiInput $input): array
    {
        $kept = array_values(array_filter(EditCompetitionRound::FIELDS, static fn (string $field): bool => $input->has($field) === false));

        // The zone is sent only together with the start; a start without it keeps the round's zone
        if ($input->has('startsAt') && $input->has('timezone') === false) {
            $kept = array_values(array_diff($kept, ['timezone']));
        }

        return $kept;
    }
}
