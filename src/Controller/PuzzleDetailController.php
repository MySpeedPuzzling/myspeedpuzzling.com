<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Query\GetPuzzleCollections;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PuzzleDetailController extends AbstractController
{
    public function __construct(
        readonly private GetPuzzleOverview $getPuzzleOverview,
        readonly private GetUserPuzzleStatuses $getUserPuzzleStatuses,
        readonly private TranslatorInterface $translator,
        readonly private GetTags $getTags,
        readonly private GetPuzzleCollections $getPuzzleCollections,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetSellSwapListItems $getSellSwapListItems,
        readonly private GetPendingPuzzleProposals $getPendingPuzzleProposals,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzle/{puzzleId}',
            'en' => '/en/puzzle/{puzzleId}',
            'es' => '/es/puzzle/{puzzleId}',
            'ja' => '/ja/パズル/{puzzleId}',
            'fr' => '/fr/puzzle/{puzzleId}',
            'de' => '/de/puzzle/{puzzleId}',
        ],
        name: 'puzzle_detail',
    )]
    #[Route(
        path: [
            'cs' => '/skladam-puzzle/{puzzleId}',
            'en' => '/solving-puzzle/{puzzleId}',
            'es' => '/es/resolviendo-puzzle/{puzzleId}',
            'ja' => '/ja/パズル解決中/{puzzleId}',
            'fr' => '/fr/resoudre-puzzle/{puzzleId}',
            'de' => '/de/puzzle-loesen/{puzzleId}',
        ],
        name: 'puzzle_detail_qr',
    )]
    public function __invoke(string $puzzleId, #[CurrentUser] null|UserInterface $user, Request $request): Response
    {
        try {
            $puzzle = $this->getPuzzleOverview->byId($puzzleId);
        } catch (PuzzleNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.puzzle_not_found'));

            return $this->redirectToRoute('puzzles');
        }

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        $puzzleStatuses = $this->getUserPuzzleStatuses->byPlayerId($loggedPlayer?->playerId);

        $puzzleCollections = [];
        if ($loggedPlayer !== null) {
            $puzzleCollections = $this->getPuzzleCollections->byPlayerAndPuzzle($loggedPlayer->playerId, $puzzleId);
        }

        return $this->render('puzzle_detail.html.twig', [
            'puzzle' => $puzzle,
            'puzzle_statuses' => $puzzleStatuses,
            'tags' => $this->getTags->forPuzzle($puzzleId),
            'puzzle_collections' => $puzzleCollections,
            'logged_player' => $loggedPlayer,
            'offers_count' => $this->getSellSwapListItems->countByPuzzleId($puzzleId),
            'has_pending_proposals' => $this->getPendingPuzzleProposals->hasPendingForPuzzle($puzzleId),
        ]);
    }
}
