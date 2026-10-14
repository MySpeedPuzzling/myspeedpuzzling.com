<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\CheckOnlineRoundFinishPhotos;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Check the finish photos again" on a round's online timing page, after its reference image changed
 * (docs/features/online-competitions/README.md §5.8): one CheckOnlineRoundFinishPhotos with force - the same as
 * `myspeedpuzzling:online-cv:check-round --force` - so every verified finish photo of the round is checked again with
 * the reference as it is now (the worker's verdict replaces the one of its own version). One click = one dispatch, the
 * checks run on the async queue. Organisers (ONLINE_ROUNDS_MANAGE), the forms' stateless CSRF token; an expired one
 * changes nothing and says so. While the check is off nothing is sent (the handler answers 0). Back to the page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class RecheckOnlineRoundFinishPhotosController extends AbstractController
{
    // The forms' own stateless token id (config/packages/csrf.php)
    public const string CSRF_TOKEN_ID = 'submit';

    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/nastaveni/zkontrolovat-fotky',
            'en' => '/en/online-rounds/{roundId}/settings/check-finish-photos',
            'es' => '/es/online-rounds/{roundId}/settings/check-finish-photos',
            'ja' => '/ja/online-rounds/{roundId}/settings/check-finish-photos',
            'fr' => '/fr/online-rounds/{roundId}/settings/check-finish-photos',
            'de' => '/de/online-rounds/{roundId}/settings/check-finish-photos',
        ],
        name: 'online_round_recheck_finish_photos',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $this->denyAccessUnlessGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $round->competition->id->toString());
        $roundId = $round->id->toString();

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('online_rounds.settings.flash.expired'));
        } else {
            $sent = $this->messageBus->dispatch(new CheckOnlineRoundFinishPhotos($roundId, force: true))->last(HandledStamp::class)?->getResult();

            $this->addFlash('success', $this->translator->trans('online_rounds.settings.reference.flash.rechecked', [
                '%count%' => is_int($sent) ? $sent : 0,
            ]));
        }

        $back = array_filter([
            'return' => $request->query->getString('return'),
            'return_title' => $request->query->getString('return_title'),
        ], static fn (string $value): bool => $value !== '');

        return $this->redirect(
            $this->generateUrl('online_round_settings', ['roundId' => $roundId] + $back) . '#online-reference-image',
            Response::HTTP_SEE_OTHER,
        );
    }
}
