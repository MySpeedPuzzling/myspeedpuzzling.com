<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Services\Api\ApiTokenOwner;
use SpeedPuzzling\Web\Value\SolvingTime;
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
        private ApiTokenOwner $tokenOwner,
        private GetPlayerPrediction $getPlayerPrediction,
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
        if ($data->roundId !== null) {
            $this->competitionRoundRepository->get($data->roundId);
        }

        $finishedAt = $data->finishedAt !== null ? new DateTimeImmutable($data->finishedAt) : null;

        $this->messageBus->dispatch(
            new AddPuzzleSolvingTime(
                timeId: $timeId,
                userId: $userId,
                puzzleId: $data->puzzleId,
                competitionId: null,
                time: $data->time,
                comment: $data->comment,
                finishedPuzzlesPhoto: null,
                groupPlayers: $data->groupPlayers,
                finishedAt: $finishedAt,
                firstAttempt: $data->firstAttempt,
                unboxed: $data->unboxed,
                roundId: $data->roundId,
            ),
        );

        // The same parser the handler stores from (SolvingTime::fromUserInput);
        // the input regex guarantees the HH:MM:SS / MM:SS shape it asserts.
        $timeSeconds = SolvingTime::fromUserInput($data->time)->seconds;

        return new SolvingTimeResponse(
            timeId: $timeId->toString(),
            puzzleId: $data->puzzleId,
            timeSeconds: $timeSeconds,
            finishedAt: $finishedAt?->format('c'),
            firstAttempt: $data->firstAttempt,
            unboxed: $data->unboxed,
            comment: $data->comment,
            roundId: $data->roundId,
            prediction: $this->predictionBefore($data, $timeSeconds, $timeId->toString()),
        );
    }

    /**
     * The prediction that applied *before* this solve - what the website's added-time
     * recap shows (AddedTimeRecapController: solo, time present, owner not opted out;
     * the template reveals it to members only). The new time is excluded from the
     * prediction query, so personal_solve_count is the count before it. Null when the
     * token is not entitled to one: group time, no time, not a member, opted out, or an
     * OAuth2 token without results:read (the write scope alone does not grant reading
     * insights - the same PAT / results:read rule as every other prediction object).
     * The cheap checks come first so a non-eligible request costs at most the one
     * owner-profile query.
     */
    private function predictionBefore(CreateSolvingTimeInput $data, null|int $timeSeconds, string $timeId): null|TimePredictionResponse
    {
        // A non-empty group_players list always makes a duo/team time (or fails in the handler)
        if ($data->groupPlayers !== [] || $timeSeconds === null) {
            return null;
        }

        if ($this->tokenOwner->canReadResults() === false || $this->tokenOwner->isMember() === false) {
            return null;
        }

        $profile = $this->tokenOwner->profile();

        if ($profile === null || $profile->timePredictionsOptedOut) {
            return null;
        }

        return TimePredictionResponse::fromResult(
            $this->getPlayerPrediction->forPuzzle($profile->playerId, $data->puzzleId, excludeTimeId: $timeId),
        );
    }
}
