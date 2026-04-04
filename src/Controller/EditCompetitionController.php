<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\FormType\CompetitionFormType;
use SpeedPuzzling\Web\Message\EditCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class EditCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-udalost/{competitionId}',
            'en' => '/en/edit-event/{competitionId}',
            'es' => '/es/edit-event/{competitionId}',
            'ja' => '/ja/edit-event/{competitionId}',
            'fr' => '/fr/edit-event/{competitionId}',
            'de' => '/de/edit-event/{competitionId}',
        ],
        name: 'edit_competition',
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->competitionRepository->get($competitionId);
        $competitionEvent = $this->getCompetitionEvents->byId($competitionId);

        $formData = CompetitionFormData::fromCompetition($competition);
        $form = $this->createForm(CompetitionFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $this->messageBus->dispatch(new EditCompetition(
                competitionId: $competitionId,
                name: $data->name ?? '',
                shortcut: $data->shortcut,
                description: $data->description,
                link: $data->link,
                registrationLink: $data->registrationLink,
                resultsLink: $data->resultsLink,
                location: $data->isOnline === true ? null : $data->location,
                locationCountryCode: $data->locationCountryCode,
                dateFrom: $data->isOnline === true ? null : $data->dateFrom,
                dateTo: $data->isOnline === true ? null : $data->dateTo,
                isOnline: $data->isOnline === true,
                logo: $data->logo,
                maintainerIds: $data->maintainers,
            ));

            $this->addFlash('success', $this->translator->trans('competition.flash.updated'));

            return $this->redirectToRoute('edit_competition', ['competitionId' => $competitionId]);
        }

        return $this->render('edit_competition.html.twig', [
            'form' => $form,
            'competition' => $competitionEvent,
        ]);
    }
}
