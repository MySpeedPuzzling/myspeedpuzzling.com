<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\ParticipantIsWaitlisted;
use SpeedPuzzling\Web\Message\RegenerateOnlineToken;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "New link" on the personal links page (docs/features/online-competitions/README.md §5.4, contract §5
 * `online_link_regenerate`): RegenerateOnlineToken - the participant's old link stops working at once. A stateless
 * CSRF token (the forms' own id: a page of many rows must not write one session token per participant); an expired one
 * changes nothing and says so. Back to the links page, at the participant's row.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class RegenerateOnlineLinkController extends AbstractController
{
    // The forms' own stateless token id (config/packages/csrf.php)
    public const string CSRF_TOKEN_ID = 'submit';

    public function __construct(
        private readonly CompetitionParticipantRepository $competitionParticipantRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-souteze/{competitionId}/odkazy/{participantId}/novy',
            'en' => '/en/online-competitions/{competitionId}/links/{participantId}/regenerate',
            'es' => '/es/online-competitions/{competitionId}/links/{participantId}/regenerate',
            'ja' => '/ja/online-competitions/{competitionId}/links/{participantId}/regenerate',
            'fr' => '/fr/online-competitions/{competitionId}/links/{participantId}/regenerate',
            'de' => '/de/online-competitions/{competitionId}/links/{participantId}/regenerate',
        ],
        name: 'online_link_regenerate',
        requirements: [
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
            'participantId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId, string $participantId): Response
    {
        $this->denyAccessUnlessGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $competitionId);

        // Another event's participant, or a removed one: 404
        $participant = $this->competitionParticipantRepository->getActiveOfCompetition($competitionId, $participantId);
        $participantId = $participant->id->toString();
        $hadLink = $participant->onlineToken !== null;

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('online_rounds.links.flash.expired'));
        } else {
            try {
                $this->messageBus->dispatch(new RegenerateOnlineToken(
                    competitionId: $participant->competition->id->toString(),
                    participantId: $participantId,
                ));

                $this->addFlash('success', $this->translator->trans(
                    $hadLink ? 'online_rounds.links.flash.regenerated' : 'online_rounds.links.flash.created',
                    ['%name%' => $participant->name],
                ));
            } catch (ParticipantIsWaitlisted) {
                $this->addFlash('warning', $this->translator->trans('online_rounds.links.flash.waitlisted', ['%name%' => $participant->name]));
            }
        }

        return $this->redirect(
            $this->generateUrl('online_links', ['competitionId' => $participant->competition->id->toString()]) . '#participant-' . $participantId,
            Response::HTTP_SEE_OTHER,
        );
    }
}
