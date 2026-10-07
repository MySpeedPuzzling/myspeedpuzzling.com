<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Exceptions\PuzzleInTwoRoundsOfCategory;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Changes only the fields sent. The round's slug never changes (shared result links keep working). A category change
 * that would put one of its puzzles into two rounds of the same category is refused (409).
 *
 * Any change that moves the round's automatic reveal (start + `revealDelayMinutes`) EARLIER - by the start, the delay or
 * both, the net moment decides - lets its secret puzzles with an automatic reveal out earlier than planned: refused
 * (409, `revealedPuzzles` - each with when, `rightAway` / `revealsAt`, and how far, `scope`) unless the body says
 * `"confirmReveal": true`. A later moment needs no yes and hides them longer on the whole site too.
 */
final class UpdateCompetitionRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetAdminCompetitions $getAdminCompetitions,
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
        $input = InternalApiInput::fromRequest($request, [...RoundInput::FIELDS, 'confirmReveal']);

        // The round as stored - in its own zone; a field left out keeps its value (its start too, to the second)
        $data = CompetitionRoundFormData::fromCompetitionRound($round);
        $startsAt = RoundInput::applyTo($input, $data) ?? $round->startsAt;
        $confirmReveal = $input->bool('confirmReveal') ?? false;

        $input->addViolations($this->validator->validate($data));
        $input->throwIfInvalid();

        assert($data->name !== null && $data->minutesLimit !== null && $data->timezone !== null);

        try {
            $this->messageBus->dispatch(new EditCompetitionRound(
                roundId: $round->id->toString(),
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
            ));
        } catch (HandlerFailedException $exception) {
            $previous = $exception->getPrevious();

            if ($previous instanceof PuzzleAlreadyInCompetitionRoundCategory) {
                throw new PuzzleInTwoRoundsOfCategory($data->category->value, $previous->conflictingRoundName, $exception);
            }

            throw $exception;
        }

        return new JsonResponse($this->getAdminCompetitions->round($round->id->toString())->toArray());
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
