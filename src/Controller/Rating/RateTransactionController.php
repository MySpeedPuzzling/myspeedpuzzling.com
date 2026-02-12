<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Rating;

use SpeedPuzzling\Web\Exceptions\DuplicateTransactionRating;
use SpeedPuzzling\Web\Exceptions\TransactionRatingExpired;
use SpeedPuzzling\Web\Exceptions\TransactionRatingNotAllowed;
use SpeedPuzzling\Web\Message\RateTransaction;
use SpeedPuzzling\Web\Query\GetTransactionRatings;
use SpeedPuzzling\Web\Repository\SoldSwappedItemRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class RateTransactionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetTransactionRatings $getTransactionRatings,
        readonly private SoldSwappedItemRepository $soldSwappedItemRepository,
    ) {
    }

    #[Route(
        path: '/en/rate-transaction/{soldSwappedItemId}',
        name: 'rate_transaction',
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, string $soldSwappedItemId): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        assert($loggedPlayer !== null);

        $soldSwappedItem = $this->soldSwappedItemRepository->get($soldSwappedItemId);

        if (!$this->getTransactionRatings->canRate($soldSwappedItemId, $loggedPlayer->playerId)) {
            $this->addFlash('warning', 'You cannot rate this transaction. It may have already been rated or the rating window has expired.');

            return $this->redirectToRoute('sold_swapped_history', ['playerId' => $loggedPlayer->playerId]);
        }

        // Determine other party for display
        $isSeller = $soldSwappedItem->seller->id->toString() === $loggedPlayer->playerId;
        $otherPlayer = $isSeller ? $soldSwappedItem->buyerPlayer : $soldSwappedItem->seller;

        if ($request->isMethod('POST')) {
            $stars = $request->request->getInt('stars');
            $reviewText = trim($request->request->getString('review_text'));

            if ($stars < 1 || $stars > 5) {
                $this->addFlash('danger', 'Please select a rating between 1 and 5 stars.');

                return $this->render('rating/rate_transaction.html.twig', [
                    'sold_swapped_item' => $soldSwappedItem,
                    'other_player' => $otherPlayer,
                    'is_seller' => $isSeller,
                ]);
            }

            try {
                $this->messageBus->dispatch(new RateTransaction(
                    soldSwappedItemId: $soldSwappedItemId,
                    reviewerId: $loggedPlayer->playerId,
                    stars: $stars,
                    reviewText: $reviewText !== '' ? $reviewText : null,
                ));

                $this->addFlash('success', 'Thank you for your rating!');

                return $this->redirectToRoute('sold_swapped_history', ['playerId' => $loggedPlayer->playerId]);
            } catch (HandlerFailedException $exception) {
                $realException = $exception->getPrevious();

                if ($realException instanceof DuplicateTransactionRating) {
                    $this->addFlash('warning', 'You have already rated this transaction.');
                } elseif ($realException instanceof TransactionRatingExpired) {
                    $this->addFlash('warning', 'The rating window has expired (30 days).');
                } elseif ($realException instanceof TransactionRatingNotAllowed) {
                    $this->addFlash('danger', 'You are not allowed to rate this transaction.');
                }

                return $this->redirectToRoute('sold_swapped_history', ['playerId' => $loggedPlayer->playerId]);
            }
        }

        return $this->render('rating/rate_transaction.html.twig', [
            'sold_swapped_item' => $soldSwappedItem,
            'other_player' => $otherPlayer,
            'is_seller' => $isSeller,
        ]);
    }
}
