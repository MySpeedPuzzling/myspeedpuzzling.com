<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\ChangePageSectionVisibility;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Shows or hides one section of the page editor (`visible` = 1 / 0) - hidden ones stay in the editor as drafts.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ChangePageSectionVisibilityController extends AbstractController
{
    public function __construct(
        private readonly CompetitionPageSectionRepository $sectionRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zobrazeni-sekce-stranky/{sectionId}',
            'en' => '/en/page-section-visibility/{sectionId}',
            'es' => '/es/page-section-visibility/{sectionId}',
            'ja' => '/ja/page-section-visibility/{sectionId}',
            'fr' => '/fr/page-section-visibility/{sectionId}',
            'de' => '/de/page-section-visibility/{sectionId}',
        ],
        name: 'change_page_section_visibility',
        requirements: ['sectionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $sectionId, Request $request): Response
    {
        $section = $this->sectionRepository->get($sectionId);
        $owner = PageSectionOwner::of($section);
        $this->denyAccessUnlessGranted($owner->editAttribute(), $owner->id());

        [$editorRoute, $editorParameters] = $owner->editorRoute();
        $backToSection = $this->redirect(
            $this->generateUrl($editorRoute, $editorParameters) . '#page-section-' . $section->id->toString(),
            Response::HTTP_SEE_OTHER,
        );

        if ($this->isCsrfTokenValid($owner->csrfTokenId(), $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('page_sections.error.expired'));

            return $backToSection;
        }

        $visible = $request->request->getString('visible') === '1';

        $this->messageBus->dispatch(new ChangePageSectionVisibility(
            sectionId: $section->id->toString(),
            visible: $visible,
        ));

        $this->addFlash('success', $this->translator->trans($visible ? 'page_sections.flash.shown' : 'page_sections.flash.hidden'));

        return $backToSection;
    }
}
