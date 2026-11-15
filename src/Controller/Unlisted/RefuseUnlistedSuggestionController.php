<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Unlisted;

use SpeedPuzzling\Web\Controller\DataImport\ImportsHomeController;
use SpeedPuzzling\Web\Message\AnswerUnlistedSuggestion;
use SpeedPuzzling\Web\Services\Unlisted\UnlistedPuzzlePage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * "Not this one" to the catalogue puzzle MySpeedPuzzling suggested for the signed-in player's unlisted puzzle. Always
 * a redirect (PRG).
 */
final class RefuseUnlistedSuggestionController extends AbstractController
{
    public function __construct(
        readonly private UnlistedPuzzlePage $page,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/{_locale}/unlisted-puzzles/{unlistedPuzzleId}/not-this-one',
        name: 'unlisted_puzzle_refuse_suggestion',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'unlistedPuzzleId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $unlistedPuzzleId): Response
    {
        $playerId = $this->page->ownerPlayerId();

        if ($this->isCsrfTokenValid(UnlistedPuzzlePage::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new AnswerUnlistedSuggestion($unlistedPuzzleId, $playerId));

        return $this->redirectToRoute('unlisted_puzzle', ['unlistedPuzzleId' => $unlistedPuzzleId]);
    }
}
