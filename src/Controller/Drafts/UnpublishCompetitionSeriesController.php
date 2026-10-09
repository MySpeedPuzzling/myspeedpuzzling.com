<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Drafts;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CannotUnpublish;
use SpeedPuzzling\Web\Message\UnpublishCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Services\Drafts\CannotUnpublishMessage;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A series back to draft - refused while any of its editions has participants, results or linked solving times (the
 * reasons come back as a flash). Always a redirect, never a 200.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class UnpublishCompetitionSeriesController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private CompetitionSeriesRepository $competitionSeriesRepository,
        readonly private TranslatorInterface $translator,
        readonly private CannotUnpublishMessage $cannotUnpublishMessage,
    ) {
    }

    #[Route(
        path: '/{_locale}/unpublish-series/{seriesId}',
        name: 'unpublish_competition_series',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $seriesId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId);

        if ($this->isCsrfTokenValid('unpublish_competition_series_' . $seriesId, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->messageBus->dispatch(new UnpublishCompetitionSeries($seriesId));
            $this->addFlash('success', $this->translator->trans('drafts_core.flash.unpublished'));
        } catch (CannotUnpublish $exception) {
            $this->addFlash('warning', $this->cannotUnpublishMessage->of($exception, $this->competitionSeriesRepository->get($seriesId)->name));
        }

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        if ($returnUrl !== null) {
            return $this->redirect($returnUrl->path);
        }

        $slug = $this->competitionSeriesRepository->get($seriesId)->slug;

        return $slug !== null
            ? $this->redirectToRoute('competition_series_detail', ['slug' => $slug])
            : $this->redirectToRoute('events');
    }
}
