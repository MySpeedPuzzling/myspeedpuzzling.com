<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\DeletePageSection;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeletePageSectionController extends AbstractController
{
    public function __construct(
        private readonly CompetitionPageSectionRepository $sectionRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/smazat-sekci-stranky/{sectionId}',
            'en' => '/en/delete-page-section/{sectionId}',
            'es' => '/es/delete-page-section/{sectionId}',
            'ja' => '/ja/delete-page-section/{sectionId}',
            'fr' => '/fr/delete-page-section/{sectionId}',
            'de' => '/de/delete-page-section/{sectionId}',
        ],
        name: 'delete_page_section',
        requirements: ['sectionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $sectionId, Request $request): Response
    {
        $section = $this->sectionRepository->get($sectionId);
        $owner = PageSectionOwner::of($section);
        $this->denyAccessUnlessGranted($owner->editAttribute(), $owner->id());

        [$editorRoute, $editorParameters] = $owner->editorRoute();

        if ($this->isCsrfTokenValid($owner->csrfTokenId(), $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('page_sections.error.expired'));

            return $this->redirectToRoute($editorRoute, $editorParameters, Response::HTTP_SEE_OTHER);
        }

        $this->messageBus->dispatch(new DeletePageSection(sectionId: $section->id->toString()));

        $this->addFlash('success', $this->translator->trans('page_sections.flash.deleted'));

        return $this->redirectToRoute($editorRoute, $editorParameters, Response::HTTP_SEE_OTHER);
    }
}
