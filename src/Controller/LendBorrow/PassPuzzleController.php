<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LendBorrow;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\FormData\PassLentPuzzleFormData;
use SpeedPuzzling\Web\FormType\PassLentPuzzleFormType;
use SpeedPuzzling\Web\Message\PassLentPuzzle;
use SpeedPuzzling\Web\Query\GetBorrowedPuzzles;
use SpeedPuzzling\Web\Query\GetCollectionItems;
use SpeedPuzzling\Web\Query\GetLentPuzzles;
use SpeedPuzzling\Web\Query\GetUnsolvedPuzzles;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Repository\LentPuzzleRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class PassPuzzleController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private LentPuzzleRepository $lentPuzzleRepository,
        readonly private PlayerRepository $playerRepository,
        readonly private GetLentPuzzles $getLentPuzzles,
        readonly private GetBorrowedPuzzles $getBorrowedPuzzles,
        readonly private GetCollectionItems $getCollectionItems,
        readonly private GetUnsolvedPuzzles $getUnsolvedPuzzles,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/predat-puzzle/{lentPuzzleId}',
            'en' => '/en/pass-puzzle/{lentPuzzleId}',
            'es' => '/es/pasar-puzzle/{lentPuzzleId}',
            'ja' => '/ja/pass-puzzle/{lentPuzzleId}',
            'fr' => '/fr/passer-puzzle/{lentPuzzleId}',
            'de' => '/de/puzzle-weitergeben/{lentPuzzleId}',
        ],
        name: 'pass_puzzle',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        string $lentPuzzleId,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        // Get the lent puzzle for puzzle info
        $lentPuzzle = $this->lentPuzzleRepository->get($lentPuzzleId);
        $puzzleId = $lentPuzzle->puzzle->id->toString();

        // Determine if user is the owner (before action modifies state)
        $isOwner = $lentPuzzle->ownerPlayer !== null
            && $lentPuzzle->ownerPlayer->id->toString() === $loggedPlayer->playerId;

        $formData = new PassLentPuzzleFormData();
        $form = $this->createForm(PassLentPuzzleFormType::class, $formData);
        $form->handleRequest($request);

        // Handle POST - pass puzzle
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PassLentPuzzleFormData $formData */
            $formData = $form->getData();

            assert($formData->newHolderCode !== null);

            // Parse input - if starts with # try to find registered player, otherwise use as plain text
            $input = $formData->newHolderCode;
            $isRegisteredPlayer = str_starts_with($input, '#');
            $cleanedInput = trim($input, "# \t\n\r\0");

            $newHolderPlayerId = null;
            $newHolderName = null;

            if ($isRegisteredPlayer) {
                try {
                    $newHolder = $this->playerRepository->getByCode($cleanedInput);
                    $newHolderPlayerId = $newHolder->id->toString();

                    // Cannot pass to yourself
                    if ($newHolderPlayerId === $loggedPlayer->playerId) {
                        $this->addFlash('danger', $this->translator->trans('lend_borrow.flash.cannot_pass_to_self'));

                        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
                    }
                } catch (PlayerNotFound) {
                    $this->addFlash('danger', $this->translator->trans('lend_borrow.flash.player_not_found'));

                    return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
                }
            } else {
                // Use plain text name for non-registered person
                $newHolderName = $cleanedInput;
            }

            // Determine if this is pass-to-owner (which behaves as return)
            $wasPassedToOwner = false;
            if ($newHolderPlayerId !== null && $lentPuzzle->ownerPlayer !== null) {
                $wasPassedToOwner = $lentPuzzle->ownerPlayer->id->toString() === $newHolderPlayerId;
            }

            // Get display name for the new holder (for UPDATE stream)
            $newHolderDisplayName = $newHolderName; // Plain text name
            if ($newHolderPlayerId !== null && !$wasPassedToOwner) {
                // Registered player - get their display name
                $newHolderPlayer = $this->playerRepository->get($newHolderPlayerId);
                $newHolderDisplayName = $newHolderPlayer->name ?? $newHolderPlayer->code;
            }

            $this->messageBus->dispatch(new PassLentPuzzle(
                lentPuzzleId: $lentPuzzleId,
                currentHolderPlayerId: $loggedPlayer->playerId,
                newHolderPlayerId: $newHolderPlayerId,
                newHolderName: $newHolderName,
            ));

            // Check if this is a Turbo request
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                // Context and tab come from form submission (hidden fields) or query string
                $context = $request->request->getString('context', $request->query->getString('context', 'detail'));
                $tab = $request->request->getString('tab', $request->query->getString('tab', ''));

                // Different response based on context
                if ($context === 'list') {
                    $lentCount = $this->getLentPuzzles->countByOwnerId($loggedPlayer->playerId);
                    $borrowedCount = $this->getBorrowedPuzzles->countByHolderId($loggedPlayer->playerId);

                    // Owner passing to someone else (not owner) = UPDATE item, don't remove
                    if ($tab === 'lent' && !$wasPassedToOwner) {
                        $lentItem = $this->getLentPuzzles->getByPuzzleIdAndOwnerId($puzzleId, $loggedPlayer->playerId);
                        $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

                        return $this->render('lend-borrow/_update_lent_item_stream.html.twig', [
                            'item' => $lentItem,
                            'puzzle_statuses' => $puzzleStatuses,
                            'message' => $this->translator->trans('lend_borrow.flash.passed'),
                        ]);
                    }

                    // Borrower passing OR pass-to-owner = REMOVE item
                    return $this->render('lend-borrow/_remove_from_list_stream.html.twig', [
                        'puzzle_id' => $puzzleId,
                        'tab' => $tab,
                        'lent_count' => $lentCount,
                        'borrowed_count' => $borrowedCount,
                        'isOwnProfile' => true,
                        'player' => $loggedPlayer,
                        'message' => $this->translator->trans('lend_borrow.flash.passed'),
                    ]);
                }

                // Called from puzzle detail page or collection detail - update badges and dropdown
                $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer->playerId);

                $templateParams = [
                    'puzzle_id' => $puzzleId,
                    'puzzle_statuses' => $puzzleStatuses,
                    'action' => 'passed',
                    'message' => $this->translator->trans('lend_borrow.flash.passed'),
                    'context' => $context,
                    // Note: logged_user is provided by Twig global (RetrieveLoggedUserProfile service)
                ];

                // For collection-detail context, fetch the collection item for full card replacement
                if ($context === 'collection-detail') {
                    $collectionId = $request->request->getString('collection_id', $request->query->getString('collection_id', ''));
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

                    if ($isOwner && !$wasPassedToOwner) {
                        // Owner passed to someone else - fetch unsolved item for card replacement
                        $unsolvedItem = $this->getUnsolvedPuzzles->byPuzzleIdAndPlayerId($puzzleId, $loggedPlayer->playerId);
                        if ($unsolvedItem !== null) {
                            $templateParams['item'] = $unsolvedItem;
                        }
                    } else {
                        // Borrower passed or owner passed to self (return) - card will be removed, update count
                        $templateParams['remaining_count'] = $this->getUnsolvedPuzzles->countByPlayerId($loggedPlayer->playerId);
                    }
                }

                return $this->render('lend-borrow/_stream.html.twig', $templateParams);
            }

            // Non-Turbo request: redirect with flash message
            $this->addFlash('success', $this->translator->trans('lend_borrow.flash.passed'));

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        // Handle GET - show modal/form
        // Context, tab, and collection_id come from query string for initial modal load
        $context = $request->query->getString('context', 'detail');
        $tab = $request->query->getString('tab', '');
        $collectionId = $request->query->getString('collection_id', '');

        $templateParams = [
            'lentPuzzleId' => $lentPuzzleId,
            'puzzle' => $lentPuzzle->puzzle,
            'form' => $form,
            'context' => $context,
            'tab' => $tab,
            'collection_id' => $collectionId,
        ];

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('lend-borrow/pass_modal.html.twig', $templateParams);
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('lend-borrow/pass.html.twig', $templateParams);
    }
}
