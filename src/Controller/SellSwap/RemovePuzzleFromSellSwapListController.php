<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\SellSwap;

use SpeedPuzzling\Web\Message\RemovePuzzleFromSellSwapList;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class RemovePuzzleFromSellSwapListController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private GetCollectionItems $getCollectionItems,
        readonly private GetUnsolvedPuzzles $getUnsolvedPuzzles,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prodat-vymenit/{puzzleId}/odebrat',
            'en' => '/en/sell-swap/{puzzleId}/remove',
            'es' => '/es/vender-intercambiar/{puzzleId}/eliminar',
            'ja' => '/ja/sell-swap/{puzzleId}/remove',
            'fr' => '/fr/vendre-echanger/{puzzleId}/supprimer',
            'de' => '/de/verkaufen-tauschen/{puzzleId}/entfernen',
        ],
        name: 'sellswap_remove',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $puzzleId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $this->messageBus->dispatch(
            new RemovePuzzleFromSellSwapList(
                playerId: $loggedPlayer->playerId,
                puzzleId: $puzzleId,
            ),
        );

        // Check if this is a Turbo request
        if ($request->headers->has('Turbo-Frame') || TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            $context = $request->request->getString('context', 'detail');

            // Different response based on context
            if ($context === 'list') {
                // Called from sell-swap list page - remove the item, update count, possibly show empty state
                $remainingCount = $this->getSellSwapListItems->countByPlayerId($loggedPlayer->playerId);

                return $this->render('sell-swap/_remove_from_list_stream.html.twig', [
                    'puzzle_id' => $puzzleId,
                    'remaining_count' => $remainingCount,
                    'isOwnProfile' => true, // Always true since only owner can remove
                    'player' => $loggedPlayer,
                    'message' => $this->translator->trans('sell_swap_list.flash.removed'),
                ]);
            }

            // Called from puzzle detail page, collection detail, or unsolved detail - update badges and dropdown
            $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

            $templateParams = [
                'puzzle_id' => $puzzleId,
                'puzzle_statuses' => $puzzleStatuses,
                'action' => 'removed',
                'message' => $this->translator->trans('sell_swap_list.flash.removed'),
                'context' => $context,
                // Note: logged_user is provided by Twig global (RetrieveLoggedUserProfile service)
            ];

            // For collection-detail context, fetch collection item for card replacement
            if ($context === 'collection-detail') {
                $collectionId = $request->request->getString('collection_id');
                // Handle __system_collection__ marker - treat as null (system collection)
                $collectionIdForQuery = ($collectionId !== '' && $collectionId !== '__system_collection__') ? $collectionId : null;

                $collectionItem = $this->getCollectionItems->getByPuzzleIdAndPlayerId(
                    $puzzleId,
                    $loggedPlayer->playerId,
                    $collectionIdForQuery,
                );

                $templateParams['item'] = $collectionItem;
                $templateParams['collection_id'] = $collectionId;
            } elseif ($context === 'unsolved-detail') {
                // For unsolved-detail context, fetch the unsolved puzzle item
                $unsolvedItem = $this->getUnsolvedPuzzles->byPuzzleIdAndPlayerId($puzzleId, $loggedPlayer->playerId);
                $templateParams['item'] = $unsolvedItem;
            } elseif ($context === 'solved-detail') {
                // For solved-detail context, fetch the solved puzzle item
                $solvedItem = $this->getPlayerSolvedPuzzles->byPuzzleIdAndPlayerId($puzzleId, $loggedPlayer->playerId);
                $templateParams['item'] = $solvedItem;
            }

            return $this->render('sell-swap/_stream.html.twig', $templateParams);
        }

        // Non-Turbo request: redirect with flash message
        $this->addFlash('success', $this->translator->trans('sell_swap_list.flash.removed'));

        $returnUrl = $request->request->getString('returnUrl');

        // Validate return URL to prevent open redirects
        if ($returnUrl !== '' && str_starts_with($returnUrl, '/') && !str_starts_with($returnUrl, '//')) {
            return $this->redirect($returnUrl);
        }

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
    }
}
