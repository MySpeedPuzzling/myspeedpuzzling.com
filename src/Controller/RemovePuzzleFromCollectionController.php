<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCollection;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/odebrat-puzzle-z-kolekce/{puzzleId}',
            'en' => '/en/remove-puzzle-from-collection/{puzzleId}',
            'es' => '/es/quitar-rompecabezas-de-coleccion/{puzzleId}',
            'ja' => '/ja/コレクションからパズルを削除/{puzzleId}',
            'fr' => '/fr/supprimer-puzzle-de-collection/{puzzleId}',
            'de' => '/de/puzzle-aus-sammlung-entfernen/{puzzleId}',
        ],
        name: 'remove_puzzle_from_collection',
        methods: ['POST'],
    )]
    public function __invoke(string $puzzleId, Request $request, #[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            throw new PlayerNotFound();
        }

        $collectionId = $request->request->get('collectionId');

        // Convert empty string to null for system collection
        if ($collectionId === '' || $collectionId === null) {
            $collectionId = null;
        } else {
            $collectionId = (string) $collectionId;
        }

        $this->messageBus->dispatch(new RemovePuzzleFromCollection(
            playerId: $player->playerId,
            puzzleId: $puzzleId,
            collectionId: $collectionId,
        ));

        $this->addFlash('success', $this->translator->trans('flashes.puzzle_removed_from_collection'));

        $returnUrl = $request->request->get('returnUrl');
        if (is_string($returnUrl) && $returnUrl !== '' && $this->isValidReturnUrl($returnUrl)) {
            return $this->redirect($returnUrl);
        }

        return $this->redirectToRoute('puzzle_detail', ['puzzleId' => $puzzleId]);
    }

    private function isValidReturnUrl(string $returnUrl): bool
    {
        // Ensure the return URL is a relative path (starts with /) to prevent open redirects
        if (!str_starts_with($returnUrl, '/')) {
            return false;
        }

        // Ensure it doesn't start with // which could be interpreted as an external URL
        if (str_starts_with($returnUrl, '//')) {
            return false;
        }

        return true;
    }
}
