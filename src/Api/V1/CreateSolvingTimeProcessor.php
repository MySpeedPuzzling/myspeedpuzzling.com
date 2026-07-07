<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<CreateSolvingTimeInput, SolvingTimeResponse>
 */
final readonly class CreateSolvingTimeProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
        private CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    /**
     * @param CreateSolvingTimeInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SolvingTimeResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        // The handler resolves the player by auth0 user id (and creates one when missing),
        // so passing the player uuid here would attribute the time to a phantom player
        $userId = $user->getPlayer()->userId;

        if ($userId === null) {
            throw new AccessDeniedHttpException('Player account has no linked user login.');
        }

        $timeId = Uuid::uuid7();

        // Validate the optional round here so an invalid/unknown id surfaces as 404
        // (CompetitionRoundNotFound is a NotFoundHttpException). The handler re-resolves
        // the round to wire it onto the entity.
        if ($data->round_id !== null) {
            $this->competitionRoundRepository->get($data->round_id);
        }

        $finishedAt = $data->finished_at !== null ? new DateTimeImmutable($data->finished_at) : null;

        $this->messageBus->dispatch(
            new AddPuzzleSolvingTime(
                timeId: $timeId,
                userId: $userId,
                puzzleId: $data->puzzle_id,
                competitionId: null,
                time: $data->time,
                comment: $data->comment,
                finishedPuzzlesPhoto: null,
                groupPlayers: $data->group_players,
                finishedAt: $finishedAt,
                firstAttempt: $data->first_attempt,
                unboxed: $data->unboxed,
                roundId: $data->round_id,
            ),
        );

        return new SolvingTimeResponse(
            time_id: $timeId->toString(),
            puzzle_id: $data->puzzle_id,
            time_seconds: null,
            finished_at: $finishedAt?->format('c'),
            first_attempt: $data->first_attempt,
            unboxed: $data->unboxed,
            comment: $data->comment,
            round_id: $data->round_id,
        );
    }
}
