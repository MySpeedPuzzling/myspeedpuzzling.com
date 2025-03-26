<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPuzzleCollection;
use SpeedPuzzling\Web\Query\GetTags;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PlayerCollectionController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private TranslatorInterface $translator,
        readonly private GetTags $getTags,
        readonly private GetPuzzleCollection $getPuzzleCollection,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/kolekce-hrace/{playerId}',
            'en' => '/en/player-collection/{playerId}',
        ],
        name: 'player_collection',
    )]
    public function __invoke(string $playerId, #[CurrentUser] UserInterface|null $user): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));

            return $this->redirectToRoute('ladder');
        }

        return $this->render('player_collection.html.twig', [
            'player' => $player,
            'tags' => $this->getTags->allGroupedPerPuzzle(),
            'puzzle_collections' => $this->getPuzzleCollection->forPlayer($player->playerId),
        ]);
    }
}
