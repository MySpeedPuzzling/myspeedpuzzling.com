<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Drafts;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\PublishCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Publish a draft one-time event or edition (docs/features/organizations/README.md "Drafts") - from the draft banner,
 * the ⋯ menu or "You organize". Always a redirect: to `return`, else the event's page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class PublishCompetitionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private CompetitionRepository $competitionRepository,
        readonly private CompetitionDetailUrl $competitionDetailUrl,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/publish-event/{competitionId}',
        name: 'publish_competition',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        if ($this->isCsrfTokenValid('publish_competition_' . $competitionId, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new PublishCompetition($competitionId));

        $competition = $this->competitionRepository->get($competitionId);
        $approved = $competition->series === null
            ? $competition->isApproved() && $competition->isRejected() === false
            : $competition->series->isApproved() && $competition->series->isRejected() === false;

        $this->addFlash('success', $this->translator->trans($approved ? 'drafts_core.flash.published' : 'drafts_core.flash.published_waiting'));

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        return $this->redirect($returnUrl->path ?? $this->competitionDetailUrl->of($competitionId));
    }
}
