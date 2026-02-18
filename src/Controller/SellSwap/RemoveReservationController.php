<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

use SpeedPuzzling\Web\Message\RemoveListingReservation;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetMarketplaceListings;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
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

final class RemoveReservationController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private SellSwapListItemRepository $sellSwapListItemRepository,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetCollectionItems $getCollectionItems,
        readonly private GetUnsolvedPuzzles $getUnsolvedPuzzles,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private GetMarketplaceListings $getMarketplaceListings,
    ) {
    }

    #[Route(
        path: '/en/sell-swap/{itemId}/unreserve',
        name: 'sell_swap_remove_reservation',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $itemId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $sellSwapListItem = $this->sellSwapListItemRepository->get($itemId);

        if ($sellSwapListItem->player->id->toString() !== $loggedPlayer->playerId) {
            throw $this->createAccessDeniedException();
        }

        $puzzleId = $sellSwapListItem->puzzle->id->toString();

        $this->messageBus->dispatch(
            new RemoveListingReservation(
                sellSwapListItemId: $itemId,
                playerId: $loggedPlayer->playerId,
            ),
        );

        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            $context = $request->request->getString('context', 'detail');
            $message = $this->translator->trans('sell_swap_list.reservation_removed.success');

            // For sell-swap list page, replace the card with updated data
            if ($context === 'list') {
                $updatedItem = $this->getSellSwapListItems->byItemId($itemId);

                return new Response(
                    $this->renderView('sell-swap/_remove_reservation_stream.html.twig', [
                        'message' => $message,
                        'context' => 'list',
                        'item' => $updatedItem,
                        'settings' => $sellSwapListItem->player->sellSwapListSettings,
                    ]),
                    Response::HTTP_OK,
                    ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
                );
            }

            // For marketplace page, replace the card with updated data
            if ($context === 'marketplace') {
                $marketplaceItem = $this->getMarketplaceListings->byItemId($itemId);

                return new Response(
                    $this->renderView('sell-swap/_remove_reservation_stream.html.twig', [
                        'message' => $message,
                        'context' => 'marketplace',
                        'marketplace_item' => $marketplaceItem,
                    ]),
                    Response::HTTP_OK,
                    ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
                );
            }

            // For conversation detail page, replace listing action buttons
            if ($context === 'conversation') {
                $otherPlayerId = $request->request->getString('other_player_id');

                return new Response(
                    $this->renderView('messaging/_conversation_listing_actions_stream.html.twig', [
                        'message' => $message,
                        'puzzle_context' => [
                            'itemId' => $itemId,
                            'reserved' => false,
                        ],
                        'other_player' => [
                            'id' => $otherPlayerId,
                        ],
                    ]),
                    Response::HTTP_OK,
                    ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
                );
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

        $this->addFlash('success', $this->translator->trans('sell_swap_list.reservation_removed.success'));

        $referer = $request->headers->get('referer');
        if ($referer !== null && $referer !== '') {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('sell_swap_list', ['playerId' => $loggedPlayer->playerId]);
    }
}
