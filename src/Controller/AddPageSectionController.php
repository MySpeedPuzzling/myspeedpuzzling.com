<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use InvalidArgumentException;
use SpeedPuzzling\Web\Entity\CompetitionPageSection;
use SpeedPuzzling\Web\Exceptions\PageSectionLimitReached;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPageSection;
use SpeedPuzzling\Web\Query\GetPageSectionOwner;
use SpeedPuzzling\Web\Results\PageSectionSubmission;
use SpeedPuzzling\Web\Services\PageSectionRequestParser;
use SpeedPuzzling\Web\Value\PageSectionOwner;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * New content section on an event, edition (?competition=) or series (?series=) page, of the type ?type=.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddPageSectionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly PageSectionRequestParser $requestParser,
        private readonly GetPageSectionOwner $getPageSectionOwner,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-sekci-stranky',
            'en' => '/en/add-page-section',
            'es' => '/es/add-page-section',
            'ja' => '/ja/add-page-section',
            'fr' => '/fr/add-page-section',
            'de' => '/de/add-page-section',
        ],
        name: 'add_page_section',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $type = PageSectionType::tryFrom($request->query->getString('type'))
            ?? throw $this->createNotFoundException();

        try {
            $owner = PageSectionOwner::fromIds($request->query->getString('competition'), $request->query->getString('series'));
        } catch (InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted($owner->editAttribute(), $owner->id());

        $ownerOverview = $this->getPageSectionOwner->of($owner);

        // A venue for an online event is not offered - nor accepted
        if ($type->isAvailableFor($ownerOverview->isOnline) === false) {
            throw $this->createNotFoundException();
        }

        [$editorRoute, $editorParameters] = $owner->editorRoute();

        // A page holds at most CompetitionPageSection::MAX_PER_PAGE sections (the editor offers no more)
        if ($ownerOverview->canAddSection() === false) {
            return $this->limitReached($editorRoute, $editorParameters);
        }

        $submission = new PageSectionSubmission(title: '', content: [], errors: []);

        if ($request->isMethod('POST')) {
            $submission = $this->requestParser->parse($type, $request, $owner);

            if ($this->isCsrfTokenValid($owner->csrfTokenId(), $request->request->getString('_token')) === false) {
                $submission = new PageSectionSubmission($submission->title, $submission->content, [
                    ['key' => 'page_sections.error.expired', 'parameters' => []],
                ]);
            }

            if ($submission->isValid()) {
                try {
                    $this->messageBus->dispatch(new AddPageSection(
                        sectionId: Uuid::uuid7(),
                        competitionId: $owner->competitionId,
                        seriesId: $owner->seriesId,
                        type: $type,
                        title: $submission->title,
                        content: $submission->content,
                    ));
                } catch (PageSectionLimitReached) {
                    // Another add of the same page took the last place meanwhile
                    return $this->limitReached($editorRoute, $editorParameters);
                }

                $this->addFlash('success', $this->translator->trans('page_sections.flash.added'));

                return $this->redirectToRoute($editorRoute, $editorParameters, Response::HTTP_SEE_OTHER);
            }
        }

        $response = $this->render('page_section_form.html.twig', [
            'section_type' => $type,
            'owner' => $owner,
            'owner_name' => $ownerOverview->name,
            'submission' => $submission,
            'form_action' => $this->generateUrl('add_page_section', [...$owner->queryParameter(), 'type' => $type->value]),
            'manage_url' => $this->generateUrl($editorRoute, $editorParameters),
            'is_new' => true,
        ], new Response(status: $submission->isValid() ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @param array<string, string> $editorParameters
     */
    private function limitReached(string $editorRoute, array $editorParameters): Response
    {
        $this->addFlash('danger', $this->translator->trans('page_sections.error.too_many_sections', [
            '%max%' => CompetitionPageSection::MAX_PER_PAGE,
        ]));

        return $this->redirectToRoute($editorRoute, $editorParameters, Response::HTTP_SEE_OTHER);
    }
}
