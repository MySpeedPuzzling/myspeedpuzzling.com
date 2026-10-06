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
 * that would put one of its puzzles into two rounds of the same category is refused (409). A new start that reveals
 * secret puzzles right away (their automatic reveal would be over) is refused (409, `revealedPuzzles`) unless the body
 * says `"confirmReveal": true`.
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
                // A start moved so that secret puzzles come out right away needs an explicit yes (409 otherwise)
                refuseToReveal: $confirmReveal === false,
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
}
