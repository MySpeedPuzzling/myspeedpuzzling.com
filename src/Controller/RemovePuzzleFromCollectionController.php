<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\CanNotFavoriteYourself;
use SpeedPuzzling\Web\Exceptions\PlayerIsAlreadyInFavorites;
use SpeedPuzzling\Web\Exceptions\PlayerIsNotInFavorites;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AddPlayerToFavorites;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Message\RemovePlayerFromFavorites;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCollection;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RemovePuzzleFromCollectionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odebrat-puzzle-z-kolekce/{puzzleId}',
            'en' => '/en/remove-puzzle-from-collection/{puzzleId}',
        ],
        name: 'remove_puzzle_from_collection',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $puzzleId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        try {
            $this->messageBus->dispatch(
                new RemovePuzzleFromCollection($player->playerId, $puzzleId),
            );

            $this->addFlash('success', $this->translator->trans('flashes.puzzle_removed_from_collection'));
        } catch (HandlerFailedException $exception) {
            $realException = $exception->getPrevious();

            if ($realException instanceof PuzzleNotFound) {
                return $this->redirectToRoute('my_profile');
            }
        }

        return $this->redirectToRoute('puzzle_detail', [
            'puzzleId' => $puzzleId,
        ]);
    }
}
