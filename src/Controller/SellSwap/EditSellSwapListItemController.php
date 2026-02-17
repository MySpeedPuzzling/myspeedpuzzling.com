<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\FormData\AddToSellSwapListFormData;
use SpeedPuzzling\Web\FormType\AddToSellSwapListFormType;
use SpeedPuzzling\Web\Message\EditSellSwapListItem;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class EditSellSwapListItemController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private SellSwapListItemRepository $sellSwapListItemRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetCollectionItems $getCollectionItems,
        readonly private GetUnsolvedPuzzles $getUnsolvedPuzzles,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws SellSwapListItemNotFound
     */
    #[Route(
        path: [
            'cs' => '/upravit-polozku-prodej-vymena/{itemId}',
            'en' => '/en/edit-sell-swap-item/{itemId}',
            'es' => '/es/editar-articulo-venta-intercambio/{itemId}',
            'ja' => '/ja/売買アイテムを編集/{itemId}',
            'fr' => '/fr/modifier-article-vente-echange/{itemId}',
            'de' => '/de/verkaufs-tausch-artikel-bearbeiten/{itemId}',
        ],
        name: 'edit_sell_swap_list_item',
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, string $itemId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $item = $this->sellSwapListItemRepository->get($itemId);

        // Only allow editing own items
        if ($item->player->id->toString() !== $loggedPlayer->playerId) {
            throw $this->createAccessDeniedException();
        }

        // Membership required
        if ($loggedPlayer->activeMembership === false) {
            $this->addFlash('warning', $this->translator->trans('sell_swap_list.membership_required.message'));

            return $this->redirectToRoute('sell_swap_list_detail', ['playerId' => $loggedPlayer->playerId]);
        }

        $formData = new AddToSellSwapListFormData();
        $formData->listingType = $item->listingType;
        $formData->price = $item->price;
        $formData->condition = $item->condition;
        $formData->comment = $item->comment;
        $formData->publishedOnMarketplace = $item->publishedOnMarketplace;

        $form = $this->createForm(AddToSellSwapListFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->messageBus->dispatch(
                new EditSellSwapListItem(
                    sellSwapListItemId: $itemId,
                    playerId: $loggedPlayer->playerId,
                    listingType: $formData->listingType,
                    price: $formData->price,
                    condition: $formData->condition,
                    comment: $formData->comment,
                    publishedOnMarketplace: $formData->publishedOnMarketplace,
                ),
            );

            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                $puzzleId = $item->puzzle->id->toString();
                $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);
                $context = $request->request->getString('context', 'detail');

                $templateParams = [
                    'puzzle_id' => $puzzleId,
                    'puzzle_statuses' => $puzzleStatuses,
                    'message' => $this->translator->trans('sell_swap_list.flash.item_updated'),
                    'context' => $context,
                ];

                if ($context === 'collection-detail') {
                    $collectionId = $request->request->getString('collection_id');
                    $collectionIdForQuery = ($collectionId !== '' && $collectionId !== '__system_collection__') ? $collectionId : null;

                    $collectionItem = $this->getCollectionItems->getByPuzzleIdAndPlayerId(
                        $puzzleId,
                        $loggedPlayer->playerId,
                        $collectionIdForQuery,
                    );

                    $templateParams['item'] = $collectionItem;
                    $templateParams['collection_id'] = $collectionId;
                } elseif ($context === 'unsolved-detail') {
                    $unsolvedItem = $this->getUnsolvedPuzzles->byPuzzleIdAndPlayerId($puzzleId, $loggedPlayer->playerId);
                    $templateParams['item'] = $unsolvedItem;
                } elseif ($context === 'solved-detail') {
                    $solvedItem = $this->getPlayerSolvedPuzzles->byPuzzleIdAndPlayerId($puzzleId, $loggedPlayer->playerId);
                    $templateParams['item'] = $solvedItem;
                }

                return $this->render('sell-swap/_stream.html.twig', $templateParams);
            }

            $this->addFlash('success', $this->translator->trans('sell_swap_list.flash.item_updated'));

            return $this->redirectToRoute('sell_swap_list_detail', ['playerId' => $loggedPlayer->playerId]);
        }

        $templateParams = [
            'form' => $form,
            'item' => $item,
            'context' => $request->query->getString('context', 'detail'),
            'collection_id' => $request->query->getString('collection_id', ''),
        ];

        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('sell-swap/edit_modal.html.twig', $templateParams);
        }

        return $this->render('sell-swap/edit_item.html.twig', [
            'form' => $form,
            'item' => $item,
            'cancelUrl' => $this->generateUrl('sell_swap_list_detail', ['playerId' => $loggedPlayer->playerId]),
        ]);
    }
}
