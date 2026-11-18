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
 * to <round>" on the round page (a full-page form). Always answers with a redirect: a full page to `back` (a same-site
 * path, checked by ReturnUrl) or the result detail, with a flash; inside the modal frame to the result detail, which
 * Turbo shows in the modal again - a refusal travels as `?round_choice=` and is said next to the picker.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ChooseSolvingTimeRoundController extends AbstractController
{
    // `?round_choice=` of the result detail after a refused choice inside the modal (PuzzleResultDetailController)
    public const string REFUSED_NOT_A_CANDIDATE = 'not_a_candidate';
    public const string REFUSED_TRY_AGAIN = 'try_again';

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
        $refusal = null;

        if ($this->isCsrfTokenValid('choose_solving_time_round_' . $timeId, $request->request->getString('_token')) === false) {
            $refusal = self::REFUSED_TRY_AGAIN;
        } else {
            try {
                $this->messageBus->dispatch(new ChooseSolvingTimeRound(
                    timeId: $timeId,
                    roundId: $roundId,
                    playerId: $profile->playerId,
                ));
            } catch (HandlerFailedException $exception) {
                $previous = $exception->getPrevious();

                // Somebody else's time (403), an unknown time or round (404): answered as such
                if ($previous instanceof HttpExceptionInterface) {
                    throw $previous;
                }

                if (!$previous instanceof RoundIsNoCandidateOfSolvingTime) {
                    throw $exception;
                }

                $refusal = self::REFUSED_NOT_A_CANDIDATE;
            }
        }

        // Inside the modal frame a flash would wait for the next page: the result detail says it next to its picker
        if ($inModal) {
            return $this->redirectToRoute('puzzle_result_detail', array_filter([
                'timeId' => $timeId,
                'round_choice' => $refusal,
            ]), Response::HTTP_SEE_OTHER);
        }

        $this->addFlash(
            $refusal === null ? 'success' : 'danger',
            $this->translator->trans(match ($refusal) {
                null => 'shared_round.flash.linked',
                self::REFUSED_TRY_AGAIN => 'shared_round.flash.try_again',
                default => 'shared_round.flash.not_a_candidate',
            }),
        );

        $back = ReturnUrl::tryFrom($request->request->getString('back'));

        if ($back !== null) {
            return $this->redirect((string) $back, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('puzzle_result_detail', ['timeId' => $timeId], Response::HTTP_SEE_OTHER);
    }
}
