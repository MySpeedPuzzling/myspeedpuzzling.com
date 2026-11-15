<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Unlisted;

use SpeedPuzzling\Web\Controller\DataImport\ImportsHomeController;
use SpeedPuzzling\Web\Message\UndoUnlistedPairing;
use SpeedPuzzling\Web\Services\Unlisted\UnlistedPuzzlePage;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedPairingOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Undo" of a pairing of the signed-in player's unlisted puzzle
 * (docs/features/data-import/unlisted-puzzles.md "Undo of a pairing"). Somebody else's pairing is a 404 (the message
 * checks). Always a redirect (PRG).
 */
final class UndoUnlistedPairingController extends AbstractController
{
    public function __construct(
        readonly private UnlistedPuzzlePage $page,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/unlisted-puzzles/{unlistedPuzzleId}/pairings/{pairingId}/undo',
        name: 'unlisted_pairing_undo',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'unlistedPuzzleId' => Requirement::UUID, 'pairingId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $unlistedPuzzleId, string $pairingId): Response
    {
        $playerId = $this->page->ownerPlayerId();

        if ($this->isCsrfTokenValid(UnlistedPuzzlePage::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $outcome = $this->messageBus->dispatch(new UndoUnlistedPairing($pairingId, $playerId))->last(HandledStamp::class)?->getResult();

        if ($outcome instanceof UnlistedPairingOutcome && $outcome->isStarted() === false) {
            $this->addFlash('warning', $this->translator->trans('unlisted_puzzles.flash.undo_' . $outcome->status));
        }

        return $this->redirectToRoute('unlisted_puzzle', ['unlistedPuzzleId' => $unlistedPuzzleId]);
    }
}
