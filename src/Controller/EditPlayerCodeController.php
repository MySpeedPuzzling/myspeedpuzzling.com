<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\NonUniquePlayerCode;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\FormData\EditProfileFormData;
use SpeedPuzzling\Web\FormData\PlayerCodeFormData;
use SpeedPuzzling\Web\FormType\EditProfileFormType;
use SpeedPuzzling\Web\FormType\PlayerCodeFormType;
use SpeedPuzzling\Web\Message\EditPlayerCode;
use SpeedPuzzling\Web\Message\EditProfile;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditPlayerCodeController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/upravit-kod-hrace',
            'en' => '/en/edit-player-code',
        ],
        name: 'edit_player_code',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $formData = PlayerCodeFormData::fromPlayerProfile($player);

        $form = $this->createForm(PlayerCodeFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->messageBus->dispatch(
                    new EditPlayerCode($player->playerId, $formData->code)
                );

                $this->addFlash('success', $this->translator->trans('flashes.profile_saved'));
            } catch (HandlerFailedException $exception) {
                $realException = $exception->getPrevious();

                if ($realException instanceof NonUniquePlayerCode) {
                    $this->addFlash('danger', $this->translator->trans('flashes.non_unique_player_code'));
                } else {
                    $this->logger->error('Changing player code failed', [
                        'exception' => $exception,
                    ]);

                    $this->addFlash('danger', $this->translator->trans('flashes.unknown_error'));
                }

                return $this->redirectToRoute('edit_player_code');
            }

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('edit-player-code.html.twig', [
            'player' => $player,
            'form' => $form,
        ]);
    }
}
