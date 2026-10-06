<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\Exceptions\PuzzleInTwoRoundsOfCategory;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Query\GetAdminCompetitions;
use SpeedPuzzling\Web\Query\GetAdminPuzzles;
use SpeedPuzzling\Web\Results\AdminPuzzle;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Adds a round to a competition (its slug is generated from the name and never changes afterwards), optionally with
 * its puzzles (`puzzleIds`, the same as PUT /internal-api/rounds/{roundId}/puzzles right after). The puzzles are
 * checked before the round is created - a refused list must not leave a round without its puzzles behind.
 */
final class CreateCompetitionRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ValidatorInterface $validator,
        private readonly GetAdminCompetitions $getAdminCompetitions,
        private readonly GetAdminPuzzles $getAdminPuzzles,
        private readonly GetCompetitionRounds $getCompetitionRounds,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private readonly CompetitionRepository $competitionRepository,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/rounds',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId, Request $request): JsonResponse
    {
        $competition = $this->getAdminCompetitions->detail($competitionId)->competition;
        $input = InternalApiInput::fromRequest($request, [...RoundInput::FIELDS, 'puzzleIds']);

        // Like the organiser's form: a new round starts in the zone of the event's other rounds, else its country's
        $otherRounds = $this->getCompetitionRoundsForManagement->ofCompetition($competition->competitionId);
        $data = CompetitionRoundFormData::forNewRound($otherRounds !== []
            ? $otherRounds[array_key_last($otherRounds)]->timezone
            : RoundTimezone::resolve(
                null,
                $competition->locationCountryCode,
                $this->competitionRepository->get($competition->competitionId)->series?->locationCountryCode,
            ));
        $startsAt = RoundInput::applyTo($input, $data);
        $puzzleIds = $input->idList('puzzleIds');

        if ($input->has('startsAt') === false) {
            $input->addError('startsAt', 'is required.');
        }

        if ($input->has('minutesLimit') === false) {
            $input->addError('minutesLimit', 'is required.');
        }

        $input->addViolations($this->validator->validate($data));
        $input->throwIfInvalid();

        assert($data->name !== null && $data->minutesLimit !== null && $startsAt !== null && $data->timezone !== null);

        if ($puzzleIds !== null && $puzzleIds !== []) {
            $puzzles = $this->getAdminPuzzles->byIds($puzzleIds);
            $unknownPuzzleIds = array_values(array_diff($puzzleIds, array_keys($puzzles)));

            if ($unknownPuzzleIds !== []) {
                throw new NotFoundHttpException(sprintf('Unknown puzzle ids: %s.', implode(', ', $unknownPuzzleIds)));
            }

            // Attached unhidden, a hidden puzzle would show on the event page (SetCompetitionRoundPuzzlesHandler
            // refuses it too - asked here, so a refused list leaves no round behind)
            $now = $this->clock->now();
            $hiddenPuzzleIds = array_keys(array_filter($puzzles, static fn (AdminPuzzle $puzzle): bool => $puzzle->isImageHiddenAt($now)));

            if ($hiddenPuzzleIds !== []) {
                throw new ConflictHttpException(sprintf(
                    'Hidden puzzles cannot be attached by the API - they would show on the event page: %s. Attach a secret puzzle on the round\'s page, where its reveal is chosen. Nothing was created.',
                    implode(', ', $hiddenPuzzleIds),
                ));
            }

            $conflictingRound = $this->getCompetitionRounds->roundWithPuzzleInCategory(
                competitionId: $competition->competitionId,
                puzzleIds: $puzzleIds,
                category: $data->category,
            );

            if ($conflictingRound !== null) {
                throw new PuzzleInTwoRoundsOfCategory($data->category->value, $conflictingRound);
            }
        }

        $roundId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddCompetitionRound(
            roundId: $roundId,
            competitionId: $competition->competitionId,
            name: $data->name,
            minutesLimit: $data->minutesLimit,
            startsAt: $startsAt,
            timezone: $data->timezone,
            badgeBackgroundColor: $data->badgeBackgroundColor,
            badgeTextColor: $data->badgeTextColor,
            category: $data->category,
            resultsLink: $data->resultsLink,
        ));

        $request->attributes->set(InternalApiAuditSubscriber::CREATED_ID_ATTRIBUTE, $roundId->toString());

        if ($puzzleIds !== null && $puzzleIds !== []) {
            try {
                $this->messageBus->dispatch(new SetCompetitionRoundPuzzles(
                    roundId: $roundId->toString(),
                    puzzleIds: $puzzleIds,
                ));
            } catch (HandlerFailedException $exception) {
                // Checked above - only a puzzle attached elsewhere in the meantime gets here, and the round exists by now
                if ($exception->getPrevious() instanceof PuzzleAlreadyInCompetitionRoundCategory) {
                    throw new ConflictHttpException(sprintf(
                        'The round %s was created, but its puzzles were not attached: %s. Set them with PUT /internal-api/rounds/%s/puzzles.',
                        $roundId->toString(),
                        $exception->getPrevious()->getMessage(),
                        $roundId->toString(),
                    ), $exception);
                }

                throw $exception;
            }
        }

        return new JsonResponse($this->getAdminCompetitions->round($roundId->toString())->toArray(), Response::HTTP_CREATED);
    }
}
