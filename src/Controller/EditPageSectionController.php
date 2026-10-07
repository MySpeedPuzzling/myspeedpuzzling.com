<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\EditPageSection;
use SpeedPuzzling\Web\Query\GetPageSectionOwner;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Results\PageSectionSubmission;
use SpeedPuzzling\Web\Services\PageSectionRequestParser;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Authorised against the page that owns the section - an edition's editor cannot change its series' sections.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EditPageSectionController extends AbstractController
{
    public function __construct(
        private readonly CompetitionPageSectionRepository $sectionRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly PageSectionRequestParser $requestParser,
        private readonly GetPageSectionOwner $getPageSectionOwner,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-sekci-stranky/{sectionId}',
            'en' => '/en/edit-page-section/{sectionId}',
            'es' => '/es/edit-page-section/{sectionId}',
            'ja' => '/ja/edit-page-section/{sectionId}',
            'fr' => '/fr/edit-page-section/{sectionId}',
            'de' => '/de/edit-page-section/{sectionId}',
        ],
        name: 'edit_page_section',
        requirements: ['sectionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(string $sectionId, Request $request): Response
    {
        $section = $this->sectionRepository->get($sectionId);
        $owner = PageSectionOwner::of($section);
        $this->denyAccessUnlessGranted($owner->editAttribute(), $owner->id());

        [$editorRoute, $editorParameters] = $owner->editorRoute();
        $submission = new PageSectionSubmission(title: $section->title ?? '', content: $section->content, errors: []);

        if ($request->isMethod('POST')) {
            $submission = $this->requestParser->parse($section->type, $request, $owner);

            if ($this->isCsrfTokenValid($owner->csrfTokenId(), $request->request->getString('_token')) === false) {
                $submission = new PageSectionSubmission($submission->title, $submission->content, [
                    ['key' => 'page_sections.error.expired', 'parameters' => []],
                ]);
            }

            if ($submission->isValid()) {
                $this->messageBus->dispatch(new EditPageSection(
                    sectionId: $section->id->toString(),
                    title: $submission->title,
                    content: $submission->content,
                ));

                $this->addFlash('success', $this->translator->trans('page_sections.flash.updated'));

                return $this->redirectToRoute($editorRoute, $editorParameters, Response::HTTP_SEE_OTHER);
            }
        }

        $response = $this->render('page_section_form.html.twig', [
            'section_type' => $section->type,
            'owner' => $owner,
            'owner_name' => $this->getPageSectionOwner->of($owner)->name,
            'submission' => $submission,
            'form_action' => $this->generateUrl('edit_page_section', ['sectionId' => $section->id->toString()]),
            'manage_url' => $this->generateUrl($editorRoute, $editorParameters),
            'is_new' => false,
        ], new Response(status: $submission->isValid() ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
