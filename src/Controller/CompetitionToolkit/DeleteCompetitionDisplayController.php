<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\DeleteCompetitionDisplay;
use SpeedPuzzling\Web\Query\GetCompetitionDisplays;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayActions;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplaysPresence;

/**
 * Deletes a display - its link stops working (D-displays-overlays.md §8.1) - a button on the display's row of the hub
 * card and the displays page. Done = back to the form's `return` (else the displays page) with a flash. The voter is
 * checked on the event of the display loaded from the database, never on an id from the request.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeleteCompetitionDisplayController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionDisplays $getCompetitionDisplays,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
        private readonly CompetitionDisplaysPresence $displaysPresence,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/editor-obrazovky/{displayId}/smazat',
            'en' => '/en/display-editor/{displayId}/delete',
            'es' => '/es/display-editor/{displayId}/delete',
            'ja' => '/ja/display-editor/{displayId}/delete',
            'fr' => '/fr/display-editor/{displayId}/delete',
            'de' => '/de/display-editor/{displayId}/delete',
        ],
        name: 'delete_competition_display',
        requirements: ['displayId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $displayId): Response
    {
        $display = $this->getCompetitionDisplays->byId($displayId);
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $display->competitionId);

        if (!$this->isCsrfTokenValid(CompetitionDisplayActions::csrfTokenId($displayId), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new DeleteCompetitionDisplay($displayId));

        // After the commit: the open page learns at once that it is no longer valid, and whether the event has displays is
        // read again (competition toolkit D5)
        $this->displaysPresence->changed($display->competitionId);
        $this->displayLiveUpdates->displayChanged($display->competitionId, $displayId);

        $this->addFlash('success', $this->translator->trans('competition_display.flash.deleted', ['%name%' => $display->name]));

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        return $this->redirect(
            $returnUrl->path ?? $this->generateUrl('competition_displays', ['competitionId' => $display->competitionId]),
            Response::HTTP_SEE_OTHER,
        );
    }
}
