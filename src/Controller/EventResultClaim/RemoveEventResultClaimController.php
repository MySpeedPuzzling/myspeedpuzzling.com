<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventResultClaim;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\EventResultClaimRefused;
use SpeedPuzzling\Web\Message\RemoveEventResultClaim;
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
 * "Not on our team" (docs/features/event-results-import/README.md "Gates"): another claimer of the result, the
 * organizer's team of its event or an admin takes a player's claim back. Who may is decided by the handler
 * (RemoveEventResultClaimHandler) - a refusal is answered in the modal.
 */
final class RemoveEventResultClaimController extends AbstractEventResultClaimController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetEventResultClaims $getEventResultClaims,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/event-result/{timeId}/not-on-our-team/{playerId}',
        name: 'event_result_claim_remove',
        requirements: ['timeId' => FirstTryConflictsController::ID_REQUIREMENT, 'playerId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(string $timeId, string $playerId, Request $request): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return $this->redirectToRoute('login');
        }

        $context = $this->getEventResultClaims->context($timeId, null);
        $claimer = $context?->claimer(strtolower($playerId));

        if ($context === null || $claimer === null || $claimer->playerId === $viewer->playerId) {
            throw new NotFoundHttpException();
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if ($this->isCsrfValid($request) === false) {
                $error = $this->translator->trans('event_result_claim.flash.expired');
            } else {
                try {
                    $this->messageBus->dispatch(new RemoveEventResultClaim(
                        timeId: $context->timeId,
                        playerId: $claimer->playerId ?? $playerId,
                        byPlayerId: $viewer->playerId,
                        reason: $request->request->getString('reason'),
                    ));

                    return $this->done(
                        $request,
                        $this->translator->trans('event_result_claim.flash.removed'),
                        $this->generateUrl('puzzle_result_detail', ['timeId' => $context->timeId]),
                    );
                } catch (EventResultClaimRefused $refused) {
                    $error = $this->translator->trans($refused->translationKey());
                }
            }
        }

        return $this->renderConfirmation(
            $request,
            $this->translator->trans('event_result_claim.remove.title'),
            'event_result_claim/_remove_body.html.twig',
            ['context' => $context, 'claimer' => $claimer, 'error' => $error],
            $error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }
}
