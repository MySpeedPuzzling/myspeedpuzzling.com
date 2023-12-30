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
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ToggleFavoritePlayerController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/pridat-hrace-k-oblibenym/{playerId}', name: 'add_player_to_favorite', methods: ['GET'])]
    #[Route(path: '/odebrat-hrace-z-oblibenych/{playerId}', name: 'remove_player_from_favorite', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] User $user, string $playerId): Response
    {
        /** @var string $routeName */
        $routeName = $request->attributes->get('_route');

        try {
            if ($routeName === 'add_player_to_favorite') {
                $this->messageBus->dispatch(
                    new AddPlayerToFavorites($user->getUserIdentifier(), $playerId),
                );

                $this->addFlash('success', 'Puzzlera jsme ti přidali do seznamu oblíbených.');
            }

            if ($routeName === 'remove_player_from_favorite') {
                $this->messageBus->dispatch(
                    new RemovePlayerFromFavorites($user->getUserIdentifier(), $playerId),
                );

                $this->addFlash('success', 'Puzzlera jsme ti odebrali ze seznamu oblíbených.');
            }
        } catch (HandlerFailedException $exception) {
            $realException = $exception->getPrevious();

            if ($realException instanceof CanNotFavoriteYourself) {
                $this->addFlash('danger', 'Nemůžeš přidat sám sebe do seznamu oblíbených. Je přeci jasné, že ty jsi tvuj vlastní nejoblíbenější puzzler!');
            }

            if ($realException instanceof PlayerIsAlreadyInFavorites) {
                $this->addFlash('warning', 'Tento puzzler je již na seznamu tvých oblíbených, podruhé ho tam tedy přidávat nebudeme ;-).');
            }

            if ($realException instanceof PlayerIsNotInFavorites) {
                $this->addFlash('warning', 'Hmm, nenašli jsme tohoto puzzlera ve tvých oblíbených, nemůžeme ho tedy odebrat ;-).');
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
