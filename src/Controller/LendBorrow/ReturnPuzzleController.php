<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LendBorrow;

use SpeedPuzzling\Web\Message\ReturnLentPuzzle;
use SpeedPuzzling\Web\Query\GetBorrowedPuzzles;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetLentPuzzles;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Repository\LentPuzzleRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class ReturnPuzzleController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private LentPuzzleRepository $lentPuzzleRepository,
        readonly private GetLentPuzzles $getLentPuzzles,
        readonly private GetBorrowedPuzzles $getBorrowedPuzzles,
        readonly private GetCollectionItems $getCollectionItems,
        readonly private GetUnsolvedPuzzles $getUnsolvedPuzzles,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/vratit-puzzle/{lentPuzzleId}',
            'en' => '/en/return-puzzle/{lentPuzzleId}',
            'es' => '/es/devolver-puzzle/{lentPuzzleId}',
            'ja' => '/ja/return-puzzle/{lentPuzzleId}',
            'fr' => '/fr/retourner-puzzle/{lentPuzzleId}',
            'de' => '/de/puzzle-zurueckgeben/{lentPuzzleId}',
        ],
        name: 'return_puzzle',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $lentPuzzleId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        // Get the lent puzzle to find the puzzle ID for Turbo Stream updates
        $lentPuzzle = $this->lentPuzzleRepository->get($lentPuzzleId);
        $puzzleId = $lentPuzzle->puzzle->id->toString();

        // Determine if user is the owner (before action modifies state)
        $isOwner = $lentPuzzle->ownerPlayer !== null
            && $lentPuzzle->ownerPlayer->id->toString() === $loggedPlayer->playerId;

        $this->messageBus->dispatch(new ReturnLentPuzzle(
            lentPuzzleId: $lentPuzzleId,
            actingPlayerId: $loggedPlayer->playerId,
        ));

        // Check if this is a Turbo request
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            $context = $request->request->getString('context', 'detail');
            $tab = $request->request->getString('tab', '');

            // Different response based on context
            if ($context === 'list') {
                // Called from lend-borrow list page - remove item, update counts, possibly show empty state
                $lentCount = $this->getLentPuzzles->countByOwnerId($loggedPlayer->playerId);
                $borrowedCount = $this->getBorrowedPuzzles->countByHolderId($loggedPlayer->playerId);

                return $this->render('lend-borrow/_remove_from_list_stream.html.twig', [
                    'puzzle_id' => $puzzleId,
                    'tab' => $tab,
                    'lent_count' => $lentCount,
                    'borrowed_count' => $borrowedCount,
                    'isOwnProfile' => true,
                    'player' => $loggedPlayer,
                    'message' => $this->translator->trans('lend_borrow.flash.returned'),
                ]);
            }

            // Called from puzzle detail page or collection detail - update badges and dropdown
            $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

            $templateParams = [
                'puzzle_id' => $puzzleId,
                'puzzle_statuses' => $puzzleStatuses,
                'action' => 'returned',
                'message' => $this->translator->trans('lend_borrow.flash.returned'),
                'context' => $context,
                // Note: logged_user is provided by Twig global (RetrieveLoggedUserProfile service)
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
                // For unsolved-detail: owner stays (replace), borrower leaves (remove)
                $templateParams['is_owner'] = $isOwner;

                if ($isOwner) {
                    // Owner's puzzle returned - fetch unsolved item for card replacement
                    $unsolvedItem = $this->getUnsolvedPuzzles->byPuzzleIdAndPlayerId($puzzleId, $loggedPlayer->playerId);
                    if ($unsolvedItem !== null) {
                        $templateParams['item'] = $unsolvedItem;
                    }
                } else {
                    // Borrower returned - card will be removed, update count
                    $templateParams['remaining_count'] = $this->getUnsolvedPuzzles->countByPlayerId($loggedPlayer->playerId);
                }
            } elseif ($context === 'solved-detail') {
                // For solved-detail: puzzles always stay (based on solving time, not ownership)
                // Just update the card with new badges
                $templateParams['is_owner'] = true; // Always replace, never remove

                $solvedItem = $this->getPlayerSolvedPuzzles->byPuzzleIdAndPlayerId($puzzleId, $loggedPlayer->playerId);
                if ($solvedItem !== null) {
                    $templateParams['item'] = $solvedItem;
                }
            }

            return $this->render('lend-borrow/_stream.html.twig', $templateParams);
        }

        // Non-Turbo request: redirect with flash message
        $this->addFlash('success', $this->translator->trans('lend_borrow.flash.returned'));

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
    }
}
