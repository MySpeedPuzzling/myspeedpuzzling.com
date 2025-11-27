<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\EditSellSwapListSettingsFormData;
use SpeedPuzzling\Web\FormType\EditSellSwapListSettingsFormType;
use SpeedPuzzling\Web\Message\EditSellSwapListSettings;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditSellSwapListSettingsController extends AbstractController
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
            'cs' => '/upravit-prodej-vymenu/{playerId}',
            'en' => '/en/edit-sell-swap-list/{playerId}',
            'es' => '/es/editar-lista-venta-intercambio/{playerId}',
            'ja' => '/ja/売買リストを編集/{playerId}',
            'fr' => '/fr/modifier-liste-vente-echange/{playerId}',
            'de' => '/de/verkaufs-tausch-liste-bearbeiten/{playerId}',
        ],
        name: 'edit_sell_swap_list_settings',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $playerId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        if ($loggedPlayer === null) {
            return $this->redirectToRoute('my_profile');
        }

        // Only allow editing own sell/swap list settings
        if ($loggedPlayer->playerId !== $playerId) {
            throw new PlayerNotFound();
        }

        // Membership required
        if (!$loggedPlayer->activeMembership) {
            $this->addFlash('warning', $this->translator->trans('sell_swap_list.membership_required.message'));
            return $this->redirectToRoute('player_collections', ['playerId' => $playerId]);
        }

        $player = $this->getPlayerProfile->byId($playerId);

        $formData = EditSellSwapListSettingsFormData::fromSettings($player->sellSwapListSettings);

        $form = $this->createForm(EditSellSwapListSettingsFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new EditSellSwapListSettings(
                    playerId: $playerId,
                    description: $formData->description,
                    currency: $formData->currency,
                    customCurrency: $formData->customCurrency,
                    shippingInfo: $formData->shippingInfo,
                    contactInfo: $formData->contactInfo,
                ),
            );

            $this->addFlash('success', $this->translator->trans('sell_swap_list.flash.settings_updated'));

            return $this->redirectToRoute('sell_swap_list_detail', ['playerId' => $playerId]);
        }

        return $this->render('sell-swap/edit_settings.html.twig', [
            'form' => $form,
            'player' => $player,
            'cancelUrl' => $this->generateUrl('sell_swap_list_detail', ['playerId' => $playerId]),
        ]);
    }
}
