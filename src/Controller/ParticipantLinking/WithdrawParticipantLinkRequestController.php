<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantLinking;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Message\WithdrawParticipantLinkRequest;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\ParticipantsSheetLiveUpdates;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Take back" on the event page's "Waiting for the organiser" state (docs/features/competitions-management/
 * participant-linking.md §6.1): the viewer's pending link request at the event is withdrawn. 303 back to the event page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class WithdrawParticipantLinkRequestController extends AbstractController
{
    public const string CSRF_PREFIX = 'participant-link-withdraw-';

    public function __construct(
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly ParticipantsSheetLiveUpdates $sheetLiveUpdates,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zrusit-zadost-o-propojeni/{competitionId}',
            'en' => '/en/withdraw-participant-link-request/{competitionId}',
            'es' => '/es/withdraw-participant-link-request/{competitionId}',
            'ja' => '/ja/withdraw-participant-link-request/{competitionId}',
            'fr' => '/fr/withdraw-participant-link-request/{competitionId}',
            'de' => '/de/withdraw-participant-link-request/{competitionId}',
        ],
        name: 'withdraw_participant_link_request',
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId, Request $request): Response
    {
        if (Uuid::isValid($competitionId) === false) {
            throw new CompetitionNotFound();
        }

        // Also the 404 of an unknown competition. For an edition its own page
        $competitionUrl = $this->competitionDetailUrl->of($competitionId) . '#taking-part';
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirect($competitionUrl, Response::HTTP_SEE_OTHER);
        }

        if ($this->isCsrfTokenValid(self::CSRF_PREFIX . strtolower($competitionId), $request->request->getString('_token')) === false) {
            $this->addFlash('warning', $this->translator->trans('participant_linking.flash.form_expired'));

            return $this->redirect($competitionUrl, Response::HTTP_SEE_OTHER);
        }

        $this->messageBus->dispatch(new WithdrawParticipantLinkRequest(strtolower($competitionId), $profile->playerId));
        $this->sheetLiveUpdates->changedAfterResponse(strtolower($competitionId));

        $this->addFlash('success', $this->translator->trans('participant_linking.flash.withdrawn'));

        return $this->redirect($competitionUrl, Response::HTTP_SEE_OTHER);
    }
}
