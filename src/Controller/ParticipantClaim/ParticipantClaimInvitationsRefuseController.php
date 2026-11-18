<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantClaim;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\RefuseParticipantClaimInvitations;
use SpeedPuzzling\Web\Query\GetParticipantClaimInvitation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Don't send me more invitations for this event" (docs/features/competitions-management/participant-linking.md §9.1,
 * R8) - the signed link of the claim invitation e-mail (ClaimInvitationsRefusalUrl), no sign-in, the
 * one-click-unsubscribe pattern of docs/features/transactional-emails.md: POST says no (the mail client's RFC 8058
 * `List-Unsubscribe=One-Click` gets a plain 200, the page's button a 303 back), idempotent; GET only shows the page -
 * mail scanners open links. Unsigned, tampered or an unknown participant: 404. The page names the event, never the
 * person's name or address.
 */
final class ParticipantClaimInvitationsRefuseController extends AbstractController
{
    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly GetParticipantClaimInvitation $getParticipantClaimInvitation,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/{_locale}/claim-invitations/no-more/{participantId}',
        name: 'participant_claim_invitations_refuse',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'participantId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $participantId): Response
    {
        if ($this->uriSigner->checkRequest($request) === false) {
            throw $this->createNotFoundException();
        }

        $invitation = $this->getParticipantClaimInvitation->byId($participantId);

        if ($invitation === null) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->messageBus->dispatch(new RefuseParticipantClaimInvitations($invitation->competitionId, $invitation->participantId));

            if ($request->request->getString('List-Unsubscribe') === 'One-Click') {
                return new Response('Unsubscribed.', Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }

            return $this->redirect($request->getUri(), Response::HTTP_SEE_OTHER);
        }

        $response = $this->render('participant_claim/invitations_refuse.html.twig', [
            'refused' => $invitation->refused,
            'event_name' => $invitation->competitionName,
            'action_url' => $request->getUri(),
        ]);

        // Personal state behind a capability URL: never in a shared cache
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
