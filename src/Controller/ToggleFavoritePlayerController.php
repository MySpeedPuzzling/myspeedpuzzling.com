<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\CanNotFavoriteYourself;
use SpeedPuzzling\Web\Exceptions\PlayerIsAlreadyInFavorites;
use SpeedPuzzling\Web\Exceptions\PlayerIsNotInFavorites;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\AddPlayerToFavorites;
use SpeedPuzzling\Web\Message\RemovePlayerFromFavorites;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ToggleFavoritePlayerController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/pridat-hrace-k-oblibenym/{playerId}',
            'en' => '/en/add-player-to-favorites/{playerId}',
            'es' => '/es/anadir-jugador-a-favoritos/{playerId}',
            'ja' => '/ja/お気に入りに追加/{playerId}',
        ],
        name: 'add_player_to_favorite',
    )]
    #[Route(
        path: [
            'cs' => '/odebrat-hrace-z-oblibenych/{playerId}',
            'en' => '/en/remove-player-from-favorites/{playerId}',
            'es' => '/es/eliminar-jugador-de-favoritos/{playerId}',
            'ja' => '/ja/お気に入りから削除/{playerId}',
        ],
        name: 'remove_player_from_favorite',
    )]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $playerId): Response
    {
        /** @var string $routeName */
        $routeName = $request->attributes->get('_route');

        try {
            if ($routeName === 'add_player_to_favorite') {
                $this->messageBus->dispatch(
                    new AddPlayerToFavorites($user->getUserIdentifier(), $playerId),
                );

                $this->addFlash('success', $this->translator->trans('flashes.puzzler_added_to_favorites'));
            }

            if ($routeName === 'remove_player_from_favorite') {
                $this->messageBus->dispatch(
                    new RemovePlayerFromFavorites($user->getUserIdentifier(), $playerId),
                );

                $this->addFlash('success', $this->translator->trans('flashes.puzzler_removed_from_favorites'));
            }
        } catch (HandlerFailedException $exception) {
            $realException = $exception->getPrevious();

            if ($realException instanceof CanNotFavoriteYourself) {
                $this->addFlash('danger', $this->translator->trans('flashes.can_not_favorite_yourself'));
            }

            if ($realException instanceof PlayerIsAlreadyInFavorites) {
                $this->addFlash('warning', $this->translator->trans('flashes.player_already_in_favorites'));
            }

            if ($realException instanceof PlayerIsNotInFavorites) {
                $this->addFlash('warning', $this->translator->trans('flashes.player_not_in_favorites'));
            }

            if ($realException instanceof PlayerNotFound) {
                return $this->redirectToRoute('my_profile');
            }
        }

        return $this->redirectToRoute('player_profile', [
            'playerId' => $playerId,
        ]);
    }
}
