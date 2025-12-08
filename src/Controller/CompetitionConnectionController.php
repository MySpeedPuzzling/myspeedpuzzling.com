<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\FormData\CompetitionConnectionFormData;
use SpeedPuzzling\Web\FormType\CompetitionConnectionFormType;
use SpeedPuzzling\Web\Message\ConnectCompetitionParticipant;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class CompetitionConnectionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private GetCompetitionParticipants $getCompetitionParticipants,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/competition-connect/{slug}',
            'en' => '/en/competition-connect/{slug}',
            'es' => '/es/conexion-competicion/{slug}',
            'ja' => '/ja/競技接続/{slug}',
            'fr' => '/fr/connexion-competition/{slug}',
            'de' => '/de/wettkampf-verbindung/{slug}',
        ],
        name: 'competition_connection',
    )]
    public function __invoke(
        #[CurrentUser] UserInterface $user,
        #[MapEntity(mapping: ['slug' => 'slug'])] Competition $competition,
        Request $request,
    ): Response {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $data = new CompetitionConnectionFormData();

        $playersMapping = $this->getCompetitionParticipants->mappingToPlayers($competition->id->toString());
        $participantsMapping = $this->getCompetitionParticipants->mappingForPairing($competition->id->toString());
        $participantName = $playersMapping[$player->playerId] ?? null;
        $data->participant = $participantName !== null ? ($participantsMapping[$participantName] ?? null) : null;

        $form = $this->createForm(CompetitionConnectionFormType::class, $data, [
            'competition' => $competition,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->messageBus->dispatch(
                    new ConnectCompetitionParticipant(
                        $competition->id->toString(),
                        $player->playerId,
                        $data->participant,
                    ),
                );
            } catch (HandlerFailedException $exception) {
                $realException = $exception->getPrevious();

                if ($realException instanceof CompetitionParticipantAlreadyConnectedToDifferentPlayer) {
                    $this->addFlash('danger', $this->translator->trans('flashes.competition_duplicate_connection'));
                    return $this->redirectToRoute('competition_connection');
                }

                throw $realException ?? $exception;
            }

            $this->addFlash('success', $this->translator->trans('flashes.competition_connection_saved'));

            return $this->redirectToRoute('event_detail', ['slug' => $competition->slug]);
        }

        return $this->render('competition_connection.html.twig', [
            'connection_form' => $form,
            'competition' => $competition,
        ]);
    }
}
