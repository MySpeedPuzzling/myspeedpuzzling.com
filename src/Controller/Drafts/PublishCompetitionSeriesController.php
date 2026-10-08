<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Drafts;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\PublishCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Publish a draft series (docs/features/organizations/README.md "Drafts") - its editions keep their own draft flags.
 * Always a redirect: to `return`, else the series page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class PublishCompetitionSeriesController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private CompetitionSeriesRepository $competitionSeriesRepository,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/publish-series/{seriesId}',
        name: 'publish_competition_series',
        requirements: ['seriesId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $seriesId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId);

        if ($this->isCsrfTokenValid('publish_competition_series_' . $seriesId, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new PublishCompetitionSeries($seriesId));

        $series = $this->competitionSeriesRepository->get($seriesId);
        $approved = $series->isApproved() && $series->isRejected() === false;

        $this->addFlash('success', $this->translator->trans($approved ? 'drafts_core.flash.published' : 'drafts_core.flash.published_waiting'));

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        if ($returnUrl !== null) {
            return $this->redirect($returnUrl->path);
        }

        return $series->slug !== null
            ? $this->redirectToRoute('competition_series_detail', ['slug' => $series->slug])
            : $this->redirectToRoute('events');
    }
}
