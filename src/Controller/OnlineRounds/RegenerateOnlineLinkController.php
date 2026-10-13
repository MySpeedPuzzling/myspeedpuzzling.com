<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OnlineTimingNotUsed;
use SpeedPuzzling\Web\Exceptions\ParticipantIsWaitlisted;
use SpeedPuzzling\Web\Message\RegenerateOnlineToken;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccessLight;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "New link" on the personal links page (docs/features/online-competitions/README.md §5.4, contract §5
 * `online_link_regenerate`): RegenerateOnlineToken - the participant's old link stops working at once; once it is
 * committed, the participant API's cached heartbeat lookup of the old link goes too (OnlineAccessLight::forget(),
 * contract §6). A stateless CSRF token (the forms' own id: a page of many rows must not write one session token per
 * participant); an expired one changes nothing and says so. Back to the links page, at the participant's row.
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
        private readonly OnlineAccessLight $onlineAccessLight,
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
        $name = $participant->name;
        // The link as this request saw it - the handler reads the row again and answers the one it really replaced
        $oldToken = $participant->onlineToken;

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('online_rounds.links.flash.expired'));
        } else {
            try {
                $replaced = $this->messageBus->dispatch(new RegenerateOnlineToken(
                    competitionId: $participant->competition->id->toString(),
                    participantId: $participantId,
                ))->last(HandledStamp::class)?->getResult();

                // Committed: the old link's cached heartbeat lookup goes now - dropped inside the transaction, a
                // heartbeat in between could have cached the old link again (OnlineAccessLight)
                foreach (array_unique(array_filter([$oldToken, is_string($replaced) ? $replaced : null])) as $token) {
                    $this->onlineAccessLight->forget($token);
                }

                $this->addFlash('success', $this->translator->trans(
                    $oldToken !== null || is_string($replaced) ? 'online_rounds.links.flash.regenerated' : 'online_rounds.links.flash.created',
                    ['%name%' => $name],
                ));
            } catch (ParticipantIsWaitlisted) {
                $this->addFlash('warning', $this->translator->trans('online_rounds.links.flash.waitlisted', ['%name%' => $name]));
            } catch (OnlineTimingNotUsed) {
                $this->addFlash('warning', $this->translator->trans('online_rounds.links.flash.not_used'));
            }
        }

        return $this->redirect(
            $this->generateUrl('online_links', ['competitionId' => $participant->competition->id->toString()]) . '#participant-' . $participantId,
            Response::HTTP_SEE_OTHER,
        );
    }
}
