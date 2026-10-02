<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Exceptions\FirstTryAlreadyTaken;
use SpeedPuzzling\Web\Exceptions\SolvingTimeAlreadySaved;
use SpeedPuzzling\Web\Exceptions\SolvingTimeIdReused;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\RecordDuplicatePrevention;
use SpeedPuzzling\Web\Query\GetSolvingTimePrediction;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Security\ApiUser;
use SpeedPuzzling\Web\Services\Api\ApiTokenOwner;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Value\SolvingTime;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<CreateSolvingTimeInput, SolvingTimeResponse>
 */
final readonly class CreateSolvingTimeProcessor implements ProcessorInterface
{
    // Fixed forever: the result id of a retried request is derived from it (Idempotency-Key)
    private const string IDEMPOTENCY_NAMESPACE = 'c3974c43-89ac-4376-a76b-2818d7be9817';

    public function __construct(
        private Security $security,
        private MessageBusInterface $messageBus,
        private CompetitionRoundRepository $competitionRoundRepository,
        private ApiTokenOwner $tokenOwner,
        private GetSolvingTimePrediction $getSolvingTimePrediction,
        private RequestStack $requestStack,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
    ) {
    }

    /**
     * @param CreateSolvingTimeInput $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SolvingTimeResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        // The handler resolves the player by its user_id identity (and creates one when missing),
        // so passing the player uuid here would attribute the time to a phantom player
        $userId = $user->getPlayer()->userId;

        if ($userId === null) {
            throw new AccessDeniedHttpException('Player account has no linked user login.');
        }

        $playerId = $user->getPlayer()->id->toString();
        $timeId = $this->timeId($playerId);

        // Validate the optional round here so an invalid/unknown id surfaces as 404
        // (CompetitionRoundNotFound is a NotFoundHttpException). The handler re-resolves
        // the round to wire it onto the entity.
        if ($data->roundId !== null) {
            $this->competitionRoundRepository->get($data->roundId);
        }

        $finishedAt = $data->finishedAt !== null ? new DateTimeImmutable($data->finishedAt) : null;

        try {
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
                    createdVia: SolvingTimeSource::Api,
                ),
            );
        } catch (HandlerFailedException $exception) {
            // The handler decides the first-try rules (docs/features/first-try-integrity.md)
            if ($exception->getPrevious() instanceof FirstTryAlreadyTaken) {
                throw FirstTryConflictResponse::from($exception->getPrevious(), $playerId);
            }

            // A retry (same Idempotency-Key) or the same request sent again within seconds: nothing was created,
            // the client gets the saved result exactly as the first request did (docs/features/duplicate-results.md)
            if ($exception->getPrevious() instanceof SolvingTimeAlreadySaved) {
                return $this->answerResend($exception->getPrevious(), $playerId);
            }

            // The key of an earlier request with another puzzle, time or day - no retry, so no answer from it
            if ($exception->getPrevious() instanceof SolvingTimeIdReused) {
                throw new IdempotencyKeyReused($exception->getPrevious());
            }

            throw $exception;
        }

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
            prediction: $this->predictionBefore($data->groupPlayers !== [], $timeSeconds, $timeId->toString()),
        );
    }

    /**
     * Optional `Idempotency-Key` header: the result id is derived from the player and the key, so a retry of a
     * request whose answer got lost finds the result it created. Without the key every request is a new result.
     */
    private function timeId(string $playerId): UuidInterface
    {
        $idempotencyKey = trim($this->requestStack->getCurrentRequest()?->headers->get('Idempotency-Key') ?? '');

        if ($idempotencyKey === '') {
            return Uuid::uuid7();
        }

        return Uuid::uuid5(self::IDEMPOTENCY_NAMESPACE, $playerId . '|' . $idempotencyKey);
    }

    private function answerResend(SolvingTimeAlreadySaved $resend, string $playerId): SolvingTimeResponse
    {
        // A dispatch of its own - the refused add was rolled back with everything written in it
        $this->messageBus->dispatch(new RecordDuplicatePrevention(
            playerId: $playerId,
            kind: DuplicatePreventionKind::ResendCaught,
            timeId: $resend->timeId,
            puzzleId: $resend->puzzleId,
            via: SolvingTimeSource::Api,
        ));

        $solvingTime = $this->puzzleSolvingTimeRepository->get($resend->timeId);

        return new SolvingTimeResponse(
            timeId: $resend->timeId,
            puzzleId: $solvingTime->puzzle->id->toString(),
            timeSeconds: $solvingTime->secondsToSolve,
            finishedAt: $solvingTime->finishedAt?->format('c'),
            firstAttempt: $solvingTime->firstAttempt,
            unboxed: $solvingTime->unboxed,
            comment: $solvingTime->comment,
            roundId: $solvingTime->competitionRound?->id->toString(),
            prediction: $this->predictionBefore($solvingTime->puzzlingTeam !== null, $solvingTime->secondsToSolve, $resend->timeId),
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
    private function predictionBefore(bool $isGroup, null|int $timeSeconds, string $timeId): null|TimePredictionResponse
    {
        // A non-empty group_players list always makes a duo/team time (or fails in the handler)
        if ($isGroup || $timeSeconds === null) {
            return null;
        }

        if ($this->tokenOwner->canReadResults() === false || $this->tokenOwner->isMember() === false) {
            return null;
        }

        $profile = $this->tokenOwner->profile();

        if ($profile === null || $profile->timePredictionsOptedOut) {
            return null;
        }

        // Stored by the add handler before the new time reached the insights tables
        return TimePredictionResponse::fromResult($this->getSolvingTimePrediction->resultForTime($timeId));
    }
}
