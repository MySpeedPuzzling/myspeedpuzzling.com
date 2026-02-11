<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

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
use Symfony\Component\Security\Http\Attribute\IsGranted;
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
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, string $playerId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        // Only allow editing own sell/swap list settings
        if ($loggedPlayer->playerId !== $playerId) {
            throw $this->createAccessDeniedException();
        }

        // Membership required
        if (!$loggedPlayer->activeMembership) {
            $this->addFlash('warning', $this->translator->trans('sell_swap_list.membership_required.message'));

            return $this->redirectToRoute('puzzle_library', ['playerId' => $playerId]);
        }

        $returnTo = $request->query->getString('returnTo', 'detail');

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
                    shippingCountries: $formData->shippingCountries,
                    shippingCost: $formData->shippingCost,
                ),
            );

            $this->addFlash('success', $this->translator->trans('sell_swap_list.flash.settings_updated'));

            if ($returnTo === 'library') {
                return $this->redirectToRoute('puzzle_library', ['playerId' => $playerId]);
            }

            return $this->redirectToRoute('sell_swap_list_detail', ['playerId' => $playerId]);
        }

        $cancelUrl = $returnTo === 'library'
            ? $this->generateUrl('puzzle_library', ['playerId' => $playerId])
            : $this->generateUrl('sell_swap_list_detail', ['playerId' => $playerId]);

        return $this->render('sell-swap/edit_settings.html.twig', [
            'form' => $form,
            'player' => $player,
            'cancelUrl' => $cancelUrl,
        ]);
    }
}
