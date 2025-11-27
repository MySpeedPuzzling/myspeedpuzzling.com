<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\EditLendBorrowListSettingsFormData;
use SpeedPuzzling\Web\FormType\EditLendBorrowListSettingsFormType;
use SpeedPuzzling\Web\Message\EditLendBorrowListSettings;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditLendBorrowListSettingsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/upravit-nastaveni-pujcovani/{playerId}',
            'en' => '/en/edit-lend-borrow-settings/{playerId}',
            'es' => '/es/editar-configuracion-prestamo/{playerId}',
            'ja' => '/ja/貸借設定を編集/{playerId}',
            'fr' => '/fr/modifier-parametres-pret/{playerId}',
            'de' => '/de/ausleih-einstellungen-bearbeiten/{playerId}',
        ],
        name: 'edit_lend_borrow_list_settings',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $playerId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        if ($loggedPlayer === null) {
            return $this->redirectToRoute('my_profile');
        }

        // Only allow editing own lend/borrow list settings
        if ($loggedPlayer->playerId !== $playerId) {
            throw new PlayerNotFound();
        }

        // Membership required for this feature
        if (!$loggedPlayer->activeMembership) {
            $this->addFlash('warning', $this->translator->trans('lend_borrow.membership_required.message'));

            return $this->redirectToRoute('player_collections', ['playerId' => $playerId]);
        }

        $player = $this->getPlayerProfile->byId($playerId);

        $formData = EditLendBorrowListSettingsFormData::fromVisibility($player->lendBorrowListVisibility);

        $form = $this->createForm(EditLendBorrowListSettingsFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new EditLendBorrowListSettings(
                    playerId: $playerId,
                    visibility: $formData->visibility ?? CollectionVisibility::Private,
                ),
            );

            $this->addFlash('success', $this->translator->trans('lend_borrow.flash.settings_updated'));

            return $this->redirectToRoute('player_collections', ['playerId' => $playerId]);
        }

        return $this->render('lend-borrow/edit_settings.html.twig', [
            'form' => $form,
            'player' => $player,
            'cancelUrl' => $this->generateUrl('player_collections', ['playerId' => $playerId]),
        ]);
    }
}
