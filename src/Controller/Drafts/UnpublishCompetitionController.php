<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Drafts;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CannotUnpublish;
use SpeedPuzzling\Web\Message\UnpublishCompetition;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
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
 * Back to draft (docs/features/organizations/README.md "Drafts") - refused while somebody joined it or a result or
 * solving time is linked to it: the reasons come back as a flash. Always a redirect, never a 200.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class UnpublishCompetitionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private CompetitionDetailUrl $competitionDetailUrl,
        readonly private TranslatorInterface $translator,
        readonly private CannotUnpublishMessage $cannotUnpublishMessage,
    ) {
    }

    #[Route(
        path: '/{_locale}/unpublish-event/{competitionId}',
        name: 'unpublish_competition',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        if ($this->isCsrfTokenValid('unpublish_competition_' . $competitionId, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->messageBus->dispatch(new UnpublishCompetition($competitionId));
            $this->addFlash('success', $this->translator->trans('drafts_core.flash.unpublished'));
        } catch (CannotUnpublish $exception) {
            $this->addFlash('warning', $this->cannotUnpublishMessage->of($exception));
        }

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        return $this->redirect($returnUrl->path ?? $this->competitionDetailUrl->of($competitionId));
    }
}
