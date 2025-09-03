<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Query\GetTags;
use SpeedPuzzling\Web\Query\GetUserSolvedPuzzles;
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
        readonly private GetUserSolvedPuzzles $getUserSolvedPuzzles,
        readonly private TranslatorInterface $translator,
        readonly private GetTags $getTags,
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

        $userSolvedPuzzles = $this->getUserSolvedPuzzles->byUserId(
            $user?->getUserIdentifier()
        );


        return $this->render('puzzle_detail.html.twig', [
            'puzzle' => $puzzle,
            'puzzles_solved_by_user' => $userSolvedPuzzles,
            'tags' => $this->getTags->forPuzzle($puzzleId),
        ]);
    }
}
