<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\EditPageSection;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Services\PageSectionRequestParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class EditPageSectionController extends AbstractController
{
    public function __construct(
        private readonly CompetitionPageSectionRepository $sectionRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly PageSectionRequestParser $requestParser,
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
    )]
    public function __invoke(string $sectionId, Request $request): Response
    {
        $section = $this->sectionRepository->get($sectionId);

        if ($section->competition !== null) {
            $ownerId = $section->competition->id->toString();
            $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $ownerId);
            $manageUrl = $this->generateUrl('manage_competition_page', ['competitionId' => $ownerId]);
            $ownerType = 'competition';
        } else {
            assert($section->series !== null);
            $ownerId = $section->series->id->toString();
            $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $ownerId);
            $manageUrl = $this->generateUrl('manage_series_page', ['seriesId' => $ownerId]);
            $ownerType = 'series';
        }

        if ($request->isMethod('POST')) {
            $this->messageBus->dispatch(new EditPageSection(
                sectionId: $sectionId,
                title: $request->request->getString('title'),
                content: $this->requestParser->parseContent($section->type, $request),
            ));

            $this->addFlash('success', $this->translator->trans('competition.page.flash.section_updated'));

            return $this->redirect($manageUrl);
        }

        return $this->render('page_section_form.html.twig', [
            'section_type' => $section->type,
            'title_value' => $section->title ?? '',
            'content' => $section->content,
            'form_action' => $request->getRequestUri(),
            'manage_url' => $manageUrl,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
        ]);
    }
}
