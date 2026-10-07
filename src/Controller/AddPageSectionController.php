<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPageSection;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionSeriesEditVoter;
use SpeedPuzzling\Web\Services\PageSectionRequestParser;
use SpeedPuzzling\Web\Value\PageSectionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AddPageSectionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly PageSectionRequestParser $requestParser,
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
    )]
    public function __invoke(Request $request): Response
    {
        $competitionId = $request->query->getString('competition');
        $seriesId = $request->query->getString('series');
        $type = PageSectionType::tryFrom($request->query->getString('type'));

        if ($type === null || ($competitionId === '') === ($seriesId === '')) {
            throw $this->createNotFoundException();
        }

        if ($competitionId !== '') {
            $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);
            $manageUrl = $this->generateUrl('manage_competition_page', ['competitionId' => $competitionId]);
        } else {
            $this->denyAccessUnlessGranted(CompetitionSeriesEditVoter::COMPETITION_SERIES_EDIT, $seriesId);
            $manageUrl = $this->generateUrl('manage_series_page', ['seriesId' => $seriesId]);
        }

        if ($request->isMethod('POST')) {
            $this->messageBus->dispatch(new AddPageSection(
                sectionId: Uuid::uuid7(),
                competitionId: $competitionId !== '' ? $competitionId : null,
                seriesId: $seriesId !== '' ? $seriesId : null,
                type: $type,
                title: $request->request->getString('title'),
                content: $this->requestParser->parseContent($type, $request),
            ));

            $this->addFlash('success', $this->translator->trans('competition.page.flash.section_added'));

            return $this->redirect($manageUrl);
        }

        return $this->render('page_section_form.html.twig', [
            'section_type' => $type,
            'title_value' => '',
            'content' => [],
            'form_action' => $request->getRequestUri(),
            'manage_url' => $manageUrl,
            'owner_type' => $competitionId !== '' ? 'competition' : 'series',
            'owner_id' => $competitionId !== '' ? $competitionId : $seriesId,
        ]);
    }
}
