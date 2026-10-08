<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\Admin\SuspiciousTimes\TimeVerificationController;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\UnmarkSolvingTimeSuspiciousDirectly;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SolvingTimeVerificationJson;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Looks fine" on a solving time - the queue's action without its card: a marked time is unmarked (it counts again,
 * the player's open reply is answered "Your time counts again"), a pending case trusted; either way this entry is
 * never raised again. Decided by INTERNAL_API_REVIEWER_PLAYER_ID. Body (optional): `note` - the player reads it with
 * the answer to their reply. Answers the time's verification state; 409 when there is nothing to unmark.
 */
final class UnmarkSolvingTimeSuspiciousController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly SolvingTimeVerificationJson $solvingTimeVerificationJson,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/solving-times/{timeId}/unmark-suspicious',
        requirements: ['timeId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $timeId, Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to credit the decision to.',
            );
        }

        $input = InternalApiInput::fromRequest($request, ['note']);
        $note = $input->string('note', maxLength: TimeVerificationController::NOTE_MAX_LENGTH);
        $input->throwIfInvalid();

        $this->messageBus->dispatch(new UnmarkSolvingTimeSuspiciousDirectly(
            timeId: strtolower($timeId),
            decidedById: $this->reviewerPlayerId,
            note: $note,
        ));

        return new JsonResponse($this->solvingTimeVerificationJson->of($timeId));
    }
}
