<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetCollectionFolders;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPuzzleCollection;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
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
        readonly private GetCollectionFolders $getCollectionFolders,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
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
        return $this->collection($playerId, null, $user);
    }

    #[Route(
        path: [
            'cs' => '/kolekce-hrace/{playerId}/slozka/{folderId}',
            'en' => '/en/player-collection/{playerId}/folder/{folderId}',
        ],
        name: 'player_collection_folder',
    )]
    public function folder(string $playerId, string $folderId, #[CurrentUser] UserInterface|null $user): Response
    {
        return $this->collection($playerId, $folderId, $user);
    }

    private function collection(string $playerId, null|string $folderId, UserInterface|null $user): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));

            return $this->redirectToRoute('ladder');
        }

        $loggedPlayerProfile = $this->retrieveLoggedUserProfile->getProfile();

        if ($player->isPrivate && $loggedPlayerProfile?->playerId !== $player->playerId) {
            return $this->redirectToRoute('player_profile', ['playerId' => $player->playerId]);
        }

        return $this->render('player_collection.html.twig', [
            'player' => $player,
            'tags' => $this->getTags->allGroupedPerPuzzle(),
            'puzzle_collections' => $this->getPuzzleCollection->forPlayerInFolder($player->playerId, $folderId),
            'collection_folders' => $this->getCollectionFolders->forPlayer($player->playerId),
            'current_folder_id' => $folderId,
        ]);
    }
}
