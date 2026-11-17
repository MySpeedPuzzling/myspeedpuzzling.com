<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\RoundIsNoCandidateOfSolvingTime;
use SpeedPuzzling\Web\Message\ChooseSolvingTimeRound;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Places the player's time in a round of its event (docs/features/competitions-management/shared-puzzles-and-divisions.md
 * §2.4 row 16): "Choose the round" in the result detail (a form inside the modal frame) and "This is my time - link it
 * to <round>" on the round page (a full-page form). Always answers with a redirect: to `back` (a same-site path, checked
 * by ReturnUrl) or to the result detail - inside the modal frame Turbo follows it there and shows the result again.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ChooseSolvingTimeRoundController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/vysledek/{timeId}/kolo',
            'en' => '/en/result/{timeId}/round',
            'es' => '/es/resultado/{timeId}/ronda',
            'ja' => '/ja/結果/{timeId}/round',
            'fr' => '/fr/resultat/{timeId}/manche',
            'de' => '/de/ergebnis/{timeId}/runde',
        ],
        name: 'choose_solving_time_round',
        requirements: ['timeId' => Requirement::UID_RFC4122],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $timeId): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $inModal = $request->headers->get('Turbo-Frame') === 'modal-frame';

        if ($profile === null) {
            return $this->redirectToRoute('my_profile');
        }

        $roundId = $request->request->getString('round_id');

        if ($this->isCsrfTokenValid('choose_solving_time_round_' . $timeId, $request->request->getString('_token')) === false) {
            $this->flash($inModal, 'danger', 'shared_round.flash.try_again');
        } else {
            try {
                $this->messageBus->dispatch(new ChooseSolvingTimeRound(
                    timeId: $timeId,
                    roundId: $roundId,
                    playerId: $profile->playerId,
                ));
                $this->flash($inModal, 'success', 'shared_round.flash.linked');
            } catch (HandlerFailedException $exception) {
                $refusal = $exception->getPrevious();

                // Somebody else's time (403), an unknown time or round (404): answered as such
                if ($refusal instanceof HttpExceptionInterface) {
                    throw $refusal;
                }

                if (!$refusal instanceof RoundIsNoCandidateOfSolvingTime) {
                    throw $exception;
                }

                $this->flash($inModal, 'danger', 'shared_round.flash.not_a_candidate');
            }
        }

        $back = ReturnUrl::tryFrom($request->request->getString('back'));

        if ($back !== null) {
            return $this->redirect((string) $back, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('puzzle_result_detail', ['timeId' => $timeId], Response::HTTP_SEE_OTHER);
    }

    /**
     * A flash only on a full page - inside the modal frame it would wait for the next page the player opens
     */
    private function flash(bool $inModal, string $type, string $key): void
    {
        if ($inModal === false) {
            $this->addFlash($type, $this->translator->trans($key));
        }
    }
}
