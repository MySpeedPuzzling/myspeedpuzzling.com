<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleToWishList;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Query\GetWishListItems;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

final class AddPuzzleToWishListController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetWishListItems $getWishListItems,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/wishlist/{puzzleId}/pridat',
            'en' => '/en/wishlist/{puzzleId}/add',
            'es' => '/es/wishlist/{puzzleId}/agregar',
            'ja' => '/ja/wishlist/{puzzleId}/add',
            'fr' => '/fr/wishlist/{puzzleId}/ajouter',
            'de' => '/de/wishlist/{puzzleId}/hinzufuegen',
        ],
        name: 'wishlist_add',
        methods: ['GET', 'POST'],
    )]
    public function __invoke(
        Request $request,
        string $puzzleId,
        #[CurrentUser] null|UserInterface $user,
    ): Response {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        // Handle POST - add to wishlist
        if ($request->isMethod('POST')) {
            if ($loggedPlayer === null) {
                $this->addFlash('warning', $this->translator->trans('wish_list.flash.login_required'));

                return $this->redirectToRoute('login');
            }

            $removeOnCollectionAdd = $request->request->getBoolean('removeOnCollectionAdd', true);

            $this->messageBus->dispatch(new AddPuzzleToWishList(
                playerId: $loggedPlayer->playerId,
                puzzleId: $puzzleId,
                removeOnCollectionAdd: $removeOnCollectionAdd,
            ));

            // Check if this is a Turbo request
            if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId(
                    $loggedPlayer->playerId
                );

                return $this->render('wishlist/_stream.html.twig', [
                    'puzzle_id' => $puzzleId,
                    'puzzle_statuses' => $puzzleStatuses,
                    'action' => 'added',
                    'message' => $this->translator->trans('wish_list.add.success'),
                ]);
            }

            // Non-Turbo request: redirect with flash message
            $this->addFlash('success', $this->translator->trans('wish_list.add.success'));

            return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
        }

        // Handle GET - show modal/form
        try {
            $puzzle = $this->getPuzzleOverview->byId($puzzleId);
        } catch (PuzzleNotFound) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $isInWishlist = false;
        if ($loggedPlayer !== null) {
            $isInWishlist = $this->getWishListItems->isPuzzleInWishList($loggedPlayer->playerId, $puzzleId);
        }

        // Turbo Frame request - return frame content only
        if ($request->headers->get('Turbo-Frame') === 'modal-frame') {
            return $this->render('wishlist/_modal.html.twig', [
                'puzzle' => $puzzle,
                'is_in_wishlist' => $isInWishlist,
                'is_logged_in' => $loggedPlayer !== null,
            ]);
        }

        // Non-Turbo request: return full page for progressive enhancement
        return $this->render('wishlist/modal.html.twig', [
            'puzzle' => $puzzle,
            'is_in_wishlist' => $isInWishlist,
            'is_logged_in' => $loggedPlayer !== null,
        ]);
    }
}
