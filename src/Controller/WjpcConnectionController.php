<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\WjpcParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\FormData\WjpcConnectionFormData;
use SpeedPuzzling\Web\FormType\WjpcConnectionFormType;
use SpeedPuzzling\Web\Message\ConnectWjpcParticipant;
use SpeedPuzzling\Web\Query\GetWjpcParticipants;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class WjpcConnectionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private GetWjpcParticipants $getWjpcParticipants,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/wjpc-2024/connect',
            'en' => '/en/wjpc-2024/connect',
        ],
        name: 'wjpc2024_connection',
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $data = new WjpcConnectionFormData();

        $playersMapping = $this->getWjpcParticipants->mappingToPlayers();
        $participantsMapping = $this->getWjpcParticipants->mappingForPairing();
        $data->participant = $participantsMapping[$playersMapping[$player->playerId] ?? null] ?? null;

        $form = $this->createForm(WjpcConnectionFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->messageBus->dispatch(
                    new ConnectWjpcParticipant(
                        $player->playerId,
                        $data->participant,
                    ),
                );
            } catch (HandlerFailedException $exception) {
                $realException = $exception->getPrevious();

                if ($realException instanceof WjpcParticipantAlreadyConnectedToDifferentPlayer) {
                    $this->addFlash('danger', $this->translator->trans('flashes.wjpc2024_duplicate_connection'));
                    return $this->redirectToRoute('wjpc2024_connection');
                }

                throw $realException ?? $exception;
            }

            $this->addFlash('success', $this->translator->trans('flashes.wjpc2024_connection_saved'));

            return $this->redirectToRoute('wjpc2024');
        }

        return $this->render('wjpc2024_connection.html.twig', [
            'connection_form' => $form,
        ]);
    }
}
