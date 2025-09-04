<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleCollection;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\FormData\MarkForSaleFormData;
use SpeedPuzzling\Web\FormType\MarkForSaleFormType;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Message\CreatePuzzleCollection;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCollection;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleCollectionItemRepository;
use SpeedPuzzling\Web\Repository\PuzzleCollectionRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED')]
final class MarkPuzzleForSaleController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleRepository $puzzleRepository,
        readonly private PlayerRepository $playerRepository,
        readonly private PuzzleCollectionRepository $collectionRepository,
        readonly private PuzzleCollectionItemRepository $collectionItemRepository,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/en/puzzle/{puzzleId}/mark-for-sale',
        name: 'mark_puzzle_for_sale',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(
        string $puzzleId,
        Request $request,
        #[CurrentUser] UserInterface $user
    ): Response {
        $loggedUserProfile = $this->retrieveLoggedUserProfile->getProfile();

        if ($loggedUserProfile === null) {
            throw $this->createAccessDeniedException();
        }

        try {
            $puzzle = $this->puzzleRepository->get($puzzleId);
        } catch (PuzzleNotFound) {
            throw $this->createNotFoundException();
        }

        $player = $this->playerRepository->get($loggedUserProfile->playerId);

        // Check if already marked for sale
        $forSaleCollection = $this->collectionRepository->findSystemCollection($player, PuzzleCollection::SYSTEM_FOR_SALE);
        $existingItem = null;

        if ($forSaleCollection !== null) {
            $existingItem = $this->collectionItemRepository->findByCollectionAndPuzzle($forSaleCollection, $puzzle);
        }

        $formData = new MarkForSaleFormData();

        // Prefill if already marked for sale
        if ($existingItem !== null) {
            $formData->price = $existingItem->price;
            $formData->currency = $existingItem->currency ?? 'USD';
            $formData->condition = $existingItem->condition;
            $formData->comment = $existingItem->comment;
            // Try to determine sale type from comment or default to sell
            if ($existingItem->comment !== null && stripos($existingItem->comment, 'lend') !== false) {
                $formData->saleType = 'lend';
            }
        }

        $form = $this->createForm(MarkForSaleFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $action = $request->request->getString('action', 'add');

            if ($action === 'remove' && $forSaleCollection !== null) {
                // Remove from for sale collection
                $this->messageBus->dispatch(new RemovePuzzleFromCollection(
                    puzzleId: $puzzleId,
                    collectionId: $forSaleCollection->id->toString(),
                    playerId: $loggedUserProfile->playerId,
                ));

                $this->addFlash('success', 'Removed from For Sale');
                return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
            }

            // Create for sale collection if it doesn't exist
            if ($forSaleCollection === null) {
                $collectionId = Uuid::uuid7();
                $this->messageBus->dispatch(new CreatePuzzleCollection(
                    collectionId: $collectionId,
                    playerId: $loggedUserProfile->playerId,
                    name: 'For Sale',
                    description: null,
                    isPublic: true,
                    systemType: PuzzleCollection::SYSTEM_FOR_SALE,
                ));

                $forSaleCollection = $this->collectionRepository->get($collectionId->toString());
            }

            // Add or update in for sale collection
            $comment = sprintf(
                'Type: %s%s',
                ucfirst($formData->saleType),
                $formData->comment ? "\n" . $formData->comment : ''
            );

            $this->messageBus->dispatch(new AddPuzzleToCollection(
                itemId: Uuid::uuid7(),
                puzzleId: $puzzleId,
                collectionId: $forSaleCollection->id->toString(),
                playerId: $loggedUserProfile->playerId,
                comment: $comment,
                price: $formData->price,
                currency: $formData->currency,
                condition: $formData->condition,
            ));

            $this->addFlash('success', $existingItem !== null ? 'For Sale details updated' : 'Marked as For Sale');

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        return $this->render('mark_for_sale.html.twig', [
            'puzzle' => $puzzle,
            'form' => $form,
            'isAlreadyForSale' => $existingItem !== null,
        ]);
    }
}
