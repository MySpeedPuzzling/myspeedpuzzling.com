<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\RemoveOnlineRoundReferenceImage;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Remove" under the reference image on a round's online timing page (docs/features/online-competitions/README.md
 * §5.8): RemoveOnlineRoundReferenceImage - the completeness check uses the catalogue image again. Organisers
 * (ONLINE_ROUNDS_MANAGE), the forms' stateless CSRF token; an expired one changes nothing and says so. Back to the page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class RemoveOnlineRoundReferenceImageController extends AbstractController
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
            'cs' => '/online-kola/{roundId}/nastaveni/referencni-obrazek/odebrat',
            'en' => '/en/online-rounds/{roundId}/settings/reference-image/remove',
            'es' => '/es/online-rounds/{roundId}/settings/reference-image/remove',
            'ja' => '/ja/online-rounds/{roundId}/settings/reference-image/remove',
            'fr' => '/fr/online-rounds/{roundId}/settings/reference-image/remove',
            'de' => '/de/online-rounds/{roundId}/settings/reference-image/remove',
        ],
        name: 'online_round_reference_image_remove',
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
            // A round without online timing: 404 (OnlineRoundNotFound)
            $this->messageBus->dispatch(new RemoveOnlineRoundReferenceImage($roundId));
            $this->addFlash('success', $this->translator->trans('online_rounds.settings.reference.flash.removed'));
        }

        return $this->redirect($this->settingsUrl($request, $roundId), Response::HTTP_SEE_OTHER);
    }

    /**
     * The settings page, with the organiser's way back it had (`return` / `return_title` ride on the form's action)
     */
    private function settingsUrl(Request $request, string $roundId): string
    {
        $back = array_filter([
            'return' => $request->query->getString('return'),
            'return_title' => $request->query->getString('return_title'),
        ], static fn (string $value): bool => $value !== '');

        return $this->generateUrl('online_round_settings', ['roundId' => $roundId] + $back) . '#online-reference-image';
    }
}
