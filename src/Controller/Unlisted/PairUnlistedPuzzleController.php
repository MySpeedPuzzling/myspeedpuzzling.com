<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Unlisted;

use SpeedPuzzling\Web\Controller\DataImport\ImportsHomeController;
use SpeedPuzzling\Web\Message\PairUnlistedPuzzle;
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
 * "It is this catalogue puzzle": the signed-in player pairs one of their unlisted puzzles
 * (docs/features/data-import/unlisted-puzzles.md "Pairing"). The message checks everything again - whose unlisted
 * puzzle it is, whether the catalogue puzzle may be chosen - and the worker does the rest; the page says what was
 * answered. Always a redirect (PRG).
 */
final class PairUnlistedPuzzleController extends AbstractController
{
    public function __construct(
        readonly private UnlistedPuzzlePage $page,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/unlisted-puzzles/{unlistedPuzzleId}/pair',
        name: 'unlisted_puzzle_pair',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'unlistedPuzzleId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $unlistedPuzzleId): Response
    {
        $playerId = $this->page->ownerPlayerId();

        if ($this->isCsrfTokenValid(UnlistedPuzzlePage::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $outcome = $this->messageBus->dispatch(new PairUnlistedPuzzle(
            $request->request->getString('request_id'),
            $unlistedPuzzleId,
            $playerId,
            $request->request->getString('puzzle_id'),
        ))->last(HandledStamp::class)?->getResult();

        if ($outcome instanceof UnlistedPairingOutcome && $outcome->isStarted() === false) {
            $this->addFlash('warning', $this->translator->trans('unlisted_puzzles.flash.' . $outcome->status));
        }

        return $this->redirectToRoute('unlisted_puzzle', ['unlistedPuzzleId' => $unlistedPuzzleId]);
    }
}
