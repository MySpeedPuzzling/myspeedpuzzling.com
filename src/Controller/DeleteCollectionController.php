<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\DeleteCollection;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DeleteCollectionController extends AbstractController
{
    public function __construct(
        readonly private CollectionRepository $collectionRepository,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws CollectionNotFound
     */
    #[Route(
        path: [
            'cs' => '/smazat-kolekci/{collectionId}',
            'en' => '/en/delete-collection/{collectionId}',
            'es' => '/es/eliminar-coleccion/{collectionId}',
            'ja' => '/ja/コレクションを削除/{collectionId}',
            'fr' => '/fr/supprimer-collection/{collectionId}',
            'de' => '/de/sammlung-loeschen/{collectionId}',
        ],
        name: 'delete_collection',
    )]
    public function __invoke(#[CurrentUser] User $user, string $collectionId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $collection = $this->collectionRepository->get($collectionId);

        if ($collection->player->id->toString() !== $player->playerId) {
            throw new CollectionNotFound();
        }

        $this->messageBus->dispatch(
            new DeleteCollection(
                collectionId: $collectionId,
                playerId: $player->playerId,
            ),
        );

        $this->addFlash('success', $this->translator->trans('collections.deleted'));

        return $this->redirectToRoute('puzzle_library', ['playerId' => $player->playerId]);
    }
}
