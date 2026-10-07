<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\DeletePageSection;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
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
        methods: ['POST'],
    )]
    public function __invoke(string $sectionId): Response
    {
        $section = $this->sectionRepository->get($sectionId);

        if ($section->competition !== null) {
            $ownerId = $section->competition->id->toString();
            $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $ownerId);
            $manageUrl = $this->generateUrl('manage_competition_page', ['competitionId' => $ownerId]);
        } else {
            assert($section->series !== null);
            $ownerId = $section->series->id->toString();
            $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $ownerId);
            $manageUrl = $this->generateUrl('manage_series_page', ['seriesId' => $ownerId]);
        }

        $this->messageBus->dispatch(new DeletePageSection(sectionId: $sectionId));

        $this->addFlash('success', $this->translator->trans('competition.page.flash.section_deleted'));

        return $this->redirect($manageUrl);
    }
}
