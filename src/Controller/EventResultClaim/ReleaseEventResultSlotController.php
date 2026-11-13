<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventResultClaim;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\EventResultClaimRefused;
use SpeedPuzzling\Web\Message\ReleaseEventResultSlot;
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
 * "Remove from my profile" - what Edit and Delete are for the player's other results, for an imported event result
 * (docs/features/event-results-import/README.md "Gates"): the result is the event's and stays, the player's spot is
 * anonymous again. The edit and delete links of such a result lead here.
 */
final class ReleaseEventResultSlotController extends AbstractEventResultClaimController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetEventResultClaims $getEventResultClaims,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/event-result/{timeId}/remove-from-profile',
        name: 'event_result_release',
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

        $context = $this->getEventResultClaims->context($timeId, null);

        // Only a spot the viewer holds - for anybody else there is nothing here
        if ($context === null || $context->isClaimer($viewer->playerId) === false) {
            throw new NotFoundHttpException();
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if ($this->isCsrfValid($request) === false) {
                $error = $this->translator->trans('event_result_claim.flash.expired');
            } else {
                try {
                    $this->messageBus->dispatch(new ReleaseEventResultSlot($context->timeId, $viewer->playerId));

                    return $this->done(
                        $request,
                        $this->translator->trans('event_result_claim.flash.released'),
                        $this->generateUrl('my_profile'),
                    );
                } catch (EventResultClaimRefused $refused) {
                    $error = $this->translator->trans($refused->translationKey());
                }
            }
        }

        return $this->renderConfirmation(
            $request,
            $this->translator->trans('event_result_claim.release.title'),
            'event_result_claim/_release_body.html.twig',
            ['context' => $context, 'error' => $error],
            $error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }
}
