<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventResultClaim;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\EventResultClaimLimitReached;
use SpeedPuzzling\Web\Exceptions\EventResultClaimRefused;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Query\GetEventResultClaims;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "This was my team" (docs/features/event-results-import/README.md "Claiming a spot"): the event, the team, the time,
 * who already claimed it, "This was my first try at this puzzle" and - when the player has a result of the puzzle near
 * the event day - "Is this the same result?". The POST dispatches ClaimEventResultSlot; every refusal says why.
 */
final class ClaimEventResultSlotController extends AbstractEventResultClaimController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetEventResultClaims $getEventResultClaims,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/event-result/{timeId}/claim',
        name: 'event_result_claim',
        requirements: ['timeId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $timeId, Request $request): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->redirectToRoute('login');
        }

        $context = $this->getEventResultClaims->context($timeId, $viewer->playerId) ?? throw new NotFoundHttpException();
        $error = null;

        if ($request->isMethod('POST')) {
            $sameResultTimeId = $request->request->getString('same_result');

            if ($this->isCsrfValid($request) === false) {
                $error = $this->translator->trans('event_result_claim.flash.expired');
            } else {
                try {
                    $this->messageBus->dispatch(new ClaimEventResultSlot(
                        timeId: $context->timeId,
                        playerId: $viewer->playerId,
                        firstTry: $request->request->getBoolean('first_try'),
                        sameResultTimeId: $sameResultTimeId !== '' ? $sameResultTimeId : null,
                    ));

                    return $this->done(
                        $request,
                        $this->translator->trans('event_result_claim.flash.claimed', ['%team%' => $context->teamName ?? '']),
                        $this->generateUrl('puzzle_result_detail', ['timeId' => $context->timeId]),
                    );
                } catch (EventResultClaimRefused $refused) {
                    $error = $this->translator->trans($refused->translationKey());
                } catch (EventResultClaimLimitReached) {
                    $error = $this->translator->trans('event_result_claim.refused.limit');
                }
            }
        }

        return $this->renderConfirmation(
            $request,
            $this->translator->trans('event_result_claim.claim.title'),
            'event_result_claim/_claim_body.html.twig',
            [
                'context' => $context,
                'viewer_id' => $viewer->playerId,
                'error' => $error,
            ],
            $error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }
}
