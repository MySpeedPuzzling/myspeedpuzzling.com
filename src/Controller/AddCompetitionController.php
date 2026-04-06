<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\FormType\CompetitionFormType;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Message\AddCompetitionSeries;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AddCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-udalost',
            'en' => '/en/add-event',
            'es' => '/es/add-event',
            'ja' => '/ja/add-event',
            'fr' => '/fr/add-event',
            'de' => '/de/add-event',
        ],
        name: 'add_competition',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('events');
        }

        $formData = new CompetitionFormData();
        $form = $this->createForm(CompetitionFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $isRecurring = $data->isOnline === true && $data->isRecurring;

            if ($isRecurring) {
                $seriesId = Uuid::uuid7();

                $this->messageBus->dispatch(new AddCompetitionSeries(
                    seriesId: $seriesId,
                    playerId: $player->playerId,
                    name: $data->name ?? '',
                    description: $data->description,
                    link: $data->link,
                    isOnline: true,
                    location: null,
                    locationCountryCode: null,
                    logo: $data->logo,
                    maintainerIds: $data->maintainers,
                ));

                $this->addFlash('success', $this->translator->trans('competition.flash.created'));

                return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId->toString()]);
            }

            $competitionId = Uuid::uuid7();

            $this->messageBus->dispatch(new AddCompetition(
                competitionId: $competitionId,
                playerId: $player->playerId,
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

            $this->addFlash('success', $this->translator->trans('competition.flash.created'));

            return $this->redirectToRoute('edit_competition', ['competitionId' => $competitionId->toString()]);
        }

        return $this->render('add_competition.html.twig', [
            'form' => $form,
        ]);
    }
}
