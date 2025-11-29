<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBorrowedPuzzles;
use SpeedPuzzling\Web\Query\GetLentPuzzles;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LendBorrowListDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetLentPuzzles $getLentPuzzles,
        readonly private GetBorrowedPuzzles $getBorrowedPuzzles,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    #[Route(
        path: [
            'cs' => '/vypujcky/{playerId}',
            'en' => '/en/lend-borrow-list/{playerId}',
            'es' => '/es/lista-prestamos/{playerId}',
            'ja' => '/ja/貸し借りリスト/{playerId}',
            'fr' => '/fr/liste-prets/{playerId}',
            'de' => '/de/leih-liste/{playerId}',
        ],
        name: 'lend_borrow_list_detail',
    )]
    public function __invoke(string $playerId, #[CurrentUser] null|UserInterface $user): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));
            return $this->redirectToRoute('ladder');
        }

        $loggedPlayerProfile = $this->retrieveLoggedUserProfile->getProfile();
        $isOwnProfile = $playerId === $loggedPlayerProfile?->playerId;
        $visibility = $player->lendBorrowListVisibility;

        // Check visibility permissions - if private and not own profile, show private page
        if ($visibility === CollectionVisibility::Private && !$isOwnProfile) {
            return $this->render('lend-borrow/private.html.twig', [
                'player' => $player,
                'collectionName' => $this->translator->trans('lend_borrow.name'),
            ]);
        }

        // For non-members viewing their own profile (but with borrowed items), show limited view
        $hasMembership = $loggedPlayerProfile !== null && $loggedPlayerProfile->activeMembership;

        $lentPuzzles = $this->getLentPuzzles->byOwnerId($player->playerId);
        $borrowedPuzzles = $this->getBorrowedPuzzles->byHolderId($player->playerId);

        return $this->render('lend-borrow/detail.html.twig', [
            'lentPuzzles' => $lentPuzzles,
            'borrowedPuzzles' => $borrowedPuzzles,
            'player' => $player,
            'isOwnProfile' => $isOwnProfile,
            'hasMembership' => $hasMembership,
            'visibility' => $visibility,
            'puzzle_statuses' => $this->getUserPuzzleStatuses->byPlayerId($playerId),
        ]);
    }
}
