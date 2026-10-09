<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Drafts;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\PublishCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
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
 * the ⋯ menu or "You organize". Always a redirect: to `return`, else the event's page. The flash says when the event
 * is not visible yet: waiting for approval, or an edition whose series is still a draft.
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

        // An admin's own publish puts nothing in front of the admins
        $this->messageBus->dispatch(new PublishCompetition($competitionId, notifyAdmin: $this->isGranted(AdminAccessVoter::ADMIN_ACCESS) === false));

        $competition = $this->competitionRepository->get($competitionId);
        $approved = $competition->series === null
            ? $competition->isApproved() && $competition->isRejected() === false
            : $competition->series->isApproved() && $competition->series->isRejected() === false;

        // An edition of a draft series stays hidden with its series
        $flash = match (true) {
            $competition->series !== null && $competition->series->isDraft => 'drafts_core.flash.published_series_draft',
            $approved => 'drafts_core.flash.published',
            default => 'drafts_core.flash.published_waiting',
        };

        $this->addFlash('success', $this->translator->trans($flash));

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        return $this->redirect($returnUrl->path ?? $this->competitionDetailUrl->of($competitionId));
    }
}
