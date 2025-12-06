<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Wishlist;

use SpeedPuzzling\Web\Message\RemovePuzzleFromWishList;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\GetWishListItems;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class RemovePuzzleFromWishListController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private GetWishListItems $getWishListItems,
        readonly private GetCollectionItems $getCollectionItems,
        readonly private GetUnsolvedPuzzles $getUnsolvedPuzzles,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odebrat-z-wish-listu/{puzzleId}',
            'en' => '/en/remove-from-wish-list/{puzzleId}',
            'es' => '/es/eliminar-de-lista-de-deseos/{puzzleId}',
            'ja' => '/ja/ウィッシュリストから削除/{puzzleId}',
            'fr' => '/fr/supprimer-de-liste-de-souhaits/{puzzleId}',
            'de' => '/de/von-wunschliste-entfernen/{puzzleId}',
        ],
        name: 'remove_puzzle_from_wish_list',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, string $puzzleId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $this->messageBus->dispatch(
            new RemovePuzzleFromWishList(
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
                // Called from wishlist detail page - remove item, update count, possibly show empty state
                $remainingCount = $this->getWishListItems->countByPlayerId($loggedPlayer->playerId);

                return $this->render('wishlist/_remove_from_list_stream.html.twig', [
                    'puzzle_id' => $puzzleId,
                    'remaining_count' => $remainingCount,
                    'isOwnProfile' => true, // Always true since only owner can remove
                    'player' => $loggedPlayer,
                    'message' => $this->translator->trans('wish_list.remove.success'),
                ]);
            }

            // Called from puzzle detail page or other contexts - update badges and dropdown
            $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

            $templateParams = [
                'puzzle_id' => $puzzleId,
                'puzzle_statuses' => $puzzleStatuses,
                'action' => 'removed',
                'message' => $this->translator->trans('wish_list.remove.success'),
                'context' => $context,
            ];

            // For collection-detail context, fetch the collection item for full card replacement
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

            return $this->render('wishlist/_stream.html.twig', $templateParams);
        }

        // Non-Turbo request: redirect with flash message
        $this->addFlash('success', $this->translator->trans('wish_list.remove.success'));

        $returnUrl = $request->request->getString('returnUrl');

        // Validate return URL to prevent open redirects
        if ($returnUrl !== '' && str_starts_with($returnUrl, '/') && !str_starts_with($returnUrl, '//')) {
            return $this->redirect($returnUrl);
        }

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
    }
}
