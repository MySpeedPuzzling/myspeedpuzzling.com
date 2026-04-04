<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\GenerateTableLayoutFormData;
use SpeedPuzzling\Web\FormType\GenerateTableLayoutFormType;
use SpeedPuzzling\Web\Message\GenerateTableLayout;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class GenerateTableLayoutController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/generovat-rozlozeni-stolu/{roundId}',
            'en' => '/en/generate-table-layout/{roundId}',
            'es' => '/es/generate-table-layout/{roundId}',
            'ja' => '/ja/generate-table-layout/{roundId}',
            'fr' => '/fr/generate-table-layout/{roundId}',
            'de' => '/de/generate-table-layout/{roundId}',
        ],
        name: 'generate_table_layout',
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();

        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        $formData = new GenerateTableLayoutFormData();
        $form = $this->createForm(GenerateTableLayoutFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->numberOfRows !== null);
            assert($data->tablesPerRow !== null);
            assert($data->spotsPerTable !== null);

            $this->messageBus->dispatch(new GenerateTableLayout(
                roundId: $roundId,
                numberOfRows: $data->numberOfRows,
                tablesPerRow: $data->tablesPerRow,
                spotsPerTable: $data->spotsPerTable,
            ));

            $this->addFlash('success', $this->translator->trans('competition.tables.flash.layout_generated'));

            return $this->redirectToRoute('manage_round_tables', ['roundId' => $roundId]);
        }

        return $this->render('generate_table_layout.html.twig', [
            'form' => $form,
            'competition' => $competition,
            'round' => $round,
        ]);
    }
}
