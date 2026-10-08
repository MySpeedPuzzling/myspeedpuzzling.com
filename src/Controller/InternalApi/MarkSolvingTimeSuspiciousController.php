<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use SpeedPuzzling\Web\Controller\Admin\SuspiciousTimes\TimeVerificationController;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\MarkSolvingTimeSuspiciousDirectly;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use SpeedPuzzling\Web\Services\SuspiciousTimes\SolvingTimeVerificationJson;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Marks a solving time "needs verification" - the queue's "Needs verification" without its card, on any time (one the
 * scan never raised too). Decided by INTERNAL_API_REVIEWER_PLAYER_ID. Body (all optional): `note` - the player reads
 * it; `reasonCodes` - which of the scan's reasons the player reads (default: every one a player may read; ignored
 * when the scan has none for this entry); `toldByHand` - true when the player is e-mailed by hand, so the app never
 * tells them again. Answers the time's verification state; 409 when the time is already marked.
 */
final class MarkSolvingTimeSuspiciousController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly SolvingTimeVerificationJson $solvingTimeVerificationJson,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/solving-times/{timeId}/mark-suspicious',
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

        $input = InternalApiInput::fromRequest($request, ['note', 'reasonCodes', 'toldByHand']);
        $note = $input->string('note', maxLength: TimeVerificationController::NOTE_MAX_LENGTH);
        $toldByHand = $input->bool('toldByHand') ?? false;
        $reasonCodes = self::reasonCodes($input, $request);
        $input->throwIfInvalid();

        $this->messageBus->dispatch(new MarkSolvingTimeSuspiciousDirectly(
            timeId: strtolower($timeId),
            decidedById: $this->reviewerPlayerId,
            note: $note,
            reasonCodes: $reasonCodes,
            toldByHand: $toldByHand,
        ));

        return new JsonResponse($this->solvingTimeVerificationJson->of($timeId));
    }

    /**
     * @return null|list<string>
     */
    private static function reasonCodes(InternalApiInput $input, Request $request): null|array
    {
        $value = InternalApiJsonBody::parse($request)['reasonCodes'] ?? null;

        if ($value === null) {
            return null;
        }

        $known = array_map(static fn (SuspiciousTimeReasonCode $code): string => $code->value, SuspiciousTimeReasonCode::cases());

        if (is_array($value) === false || array_is_list($value) === false) {
            $input->addError('reasonCodes', 'must be a list of reason codes.');

            return null;
        }

        foreach ($value as $code) {
            if (is_string($code) === false || in_array($code, $known, true) === false) {
                $input->addError('reasonCodes', sprintf('must contain only reason codes (%s).', implode(', ', $known)));

                return null;
            }
        }

        return array_values(array_unique($value));
    }
}
