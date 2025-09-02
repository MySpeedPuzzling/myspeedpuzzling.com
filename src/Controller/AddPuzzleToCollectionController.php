<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AddPuzzleToCollectionController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-puzzle-do-kolecce/{puzzleId}',
            'en' => '/en/add-puzzle-to-collection/{puzzleId}',
            'es' => '/es/anadir-puzzle-a-coleccion/{puzzleId}',
            'ja' => '/ja/コレクションに追加/{puzzleId}',
            'fr' => '/fr/ajouter-puzzle-a-collection/{puzzleId}',
            'de' => '/de/puzzle-zu-sammlung-hinzufuegen/{puzzleId}',
        ],
        name: 'add_puzzle_to_collection',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $puzzleId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        try {
            $this->messageBus->dispatch(
                new AddPuzzleToCollection($player->playerId, $puzzleId),
            );

            $this->addFlash('success', $this->translator->trans('flashes.puzzle_added_to_collection'));
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
