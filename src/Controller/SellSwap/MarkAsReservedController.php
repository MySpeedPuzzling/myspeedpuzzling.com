<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

use SpeedPuzzling\Web\FormData\MarkAsReservedFormData;
use SpeedPuzzling\Web\FormType\MarkAsReservedFormType;
use SpeedPuzzling\Web\Message\MarkListingAsReserved;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetConversationPartnersForListing;
use SpeedPuzzling\Web\Query\GetFavoritePlayers;
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

final class MarkAsReservedController extends AbstractController
{
    public function __construct(
        readonly private SellSwapListItemRepository $sellSwapListItemRepository,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetFavoritePlayers $getFavoritePlayers,
        readonly private GetConversationPartnersForListing $getConversationPartnersForListing,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetCollectionItems $getCollectionItems,
        readonly private GetUnsolvedPuzzles $getUnsolvedPuzzles,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    ) {
    }

    #[Route(
        path: '/en/sell-swap/{itemId}/reserve',
        name: 'sell_swap_mark_reserved',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $itemId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $sellSwapListItem = $this->sellSwapListItemRepository->get($itemId);

        // Verify ownership
        if ($sellSwapListItem->player->id->toString() !== $loggedPlayer->playerId) {
            throw $this->createAccessDeniedException();
        }

        // Direct POST with reservedForPlayerId (from conversation "to this puzzler" button)
        $reservedForPlayerId = $request->request->getString('reservedForPlayerId');
        if ($request->isMethod('POST') && $reservedForPlayerId !== '') {
            $this->messageBus->dispatch(
                new MarkListingAsReserved(
                    sellSwapListItemId: $itemId,
                    playerId: $loggedPlayer->playerId,
                    reservedForPlayerId: $reservedForPlayerId,
                ),
            );

            $this->addFlash('success', $this->translator->trans('sell_swap_list.reserved.success'));

            $referer = $request->headers->get('referer');
            if ($referer !== null && $referer !== '') {
                return $this->redirect($referer);
            }

            return $this->redirectToRoute('sell_swap_list_detail', ['playerId' => $loggedPlayer->playerId]);
        }

        $formData = new MarkAsReservedFormData();
        $form = $this->createForm(MarkAsReservedFormType::class, $formData);
        $form->handleRequest($request);

        // Handle form POST
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var MarkAsReservedFormData $formData */
            $formData = $form->getData();

            $this->messageBus->dispatch(new MarkListingAsReserved(
                sellSwapListItemId: $itemId,
                playerId: $loggedPlayer->playerId,
                reservedForInput: $formData->reservedForInput,
            ));

            // Check if this is a Turbo request
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                $context = $request->request->getString('context', 'detail');
                $message = $this->translator->trans('sell_swap_list.reserved.success');
                $puzzleId = $sellSwapListItem->puzzle->id->toString();

                // For sell-swap list and marketplace pages, use page refresh
                if ($context === 'list' || $context === 'marketplace') {
                    return $this->render('sell-swap/_mark_reserved_stream.html.twig', [
                        'message' => $message,
                    ]);
                }

                // For puzzle detail and library pages, use targeted stream replacements
                $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

                $templateParams = [
                    'puzzle_id' => $puzzleId,
                    'puzzle_statuses' => $puzzleStatuses,
                    'message' => $message,
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

            // Non-Turbo request: redirect with flash message
            $this->addFlash('success', $this->translator->trans('sell_swap_list.reserved.success'));

            return $this->redirectToRoute('sell_swap_list_detail', ['playerId' => $loggedPlayer->playerId]);
        }

        // Handle GET - show modal/form
        $favoritePlayers = $this->getFavoritePlayers->forPlayerId($loggedPlayer->playerId);
        $conversationPartners = $this->getConversationPartnersForListing->forListingAndSeller($itemId, $loggedPlayer->playerId);

        $templateParams = [
            'item_id' => $itemId,
            'form' => $form,
            'favorite_players' => $favoritePlayers,
            'conversation_partners' => $conversationPartners,
            'context' => $request->query->getString('context', 'detail'),
            'collection_id' => $request->query->getString('collection_id', ''),
        ];

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('sell-swap/mark_reserved_modal.html.twig', $templateParams);
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('sell-swap/mark_reserved.html.twig', $templateParams);
    }
}
