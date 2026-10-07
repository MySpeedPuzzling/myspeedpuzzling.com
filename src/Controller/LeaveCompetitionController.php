<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class LeaveCompetitionController extends AbstractController
{
    /**
     * The registration card's cancel/leave form of an event that manages registration - per event, behind a step that
     * says what happens first (docs/features/competitions-management/registration.md). Events without management keep
     * main's plain button.
     */
    public const string CSRF_PREFIX = 'competition-leave-';

    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly CompetitionDetailUrl $competitionDetailUrl,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odhlasit-se-z-udalosti/{competitionId}',
            'en' => '/en/leave-event/{competitionId}',
            'es' => '/es/dejar-evento/{competitionId}',
            'ja' => '/ja/イベント退出/{competitionId}',
            'fr' => '/fr/quitter-evenement/{competitionId}',
            'de' => '/de/event-verlassen/{competitionId}',
        ],
        name: 'leave_competition',
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId, Request $request): Response
    {
        // Also the 404 of an unknown competition. For an edition its own page, never event_detail with its slug
        $competitionUrl = $this->competitionDetailUrl->of($competitionId);
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        // A registration (possibly paid) is cancelled only through the card's confirmation, never by a bare POST
        if (
            $profile !== null
            && $this->getCompetitionEvents->byId($competitionId)->registrationManaged
            && !$this->isCsrfTokenValid(self::CSRF_PREFIX . $competitionId, $request->request->getString('_token'))
        ) {
            $this->addFlash('warning', $this->translator->trans('competition_registration.flash.form_expired'));

            return $this->redirect($competitionUrl, Response::HTTP_SEE_OTHER);
        }

        if ($profile !== null) {
            $this->messageBus->dispatch(new LeaveCompetition(
                competitionId: $competitionId,
                playerId: $profile->playerId,
            ));

            $this->addFlash('success', $this->translator->trans('flashes.competition_leave_success'));
        }

        return $this->redirect($competitionUrl);
    }
}
